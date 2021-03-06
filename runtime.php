<?php

// Essentially a namespace for some runtime globals, to keep them out of the global
// namespace
class CpsRuntime {
    public static $builtin_functions = [];
    public static $function_transforms = [];
    public static $builtin_methods = [];
    public static $stack_depth = 0;
    
    public static function include_file($file, $compiler, $is_require) {
        $include_path = get_include_path();
        if (!is_readable($file)) {
            foreach (explode(PATH_SEPARATOR, $include_path) as $path) {
                $candidate = "$path/$file";
                if (is_readable($candidate)) {
                    $file = $candidate;
                    break;
                }
            }
        }
        $file = realpath($file);
    
        $tempfile = sys_get_temp_dir() . '/cps.' . getmyuid() . $file;
    
        if (!is_readable($tempfile) || filemtime($tempfile) < filemtime($file)) {
            // recompile if out of date
            @mkdir(dirname($tempfile), 0700, true);
            exec("$compiler include $file < $file > $tempfile", $output, $return_value);
        }
        else {
            $return_value = 0;
        }
    
        if ($return_value == 0) {
            return $tempfile;
        }
        else {
            $error = implode("\n", $output) . readfile($tempfile);
            unlink($tempfile);
            echo $error;
            return $tempfile;
        }
    }

    public static function &trampoline($thunk) {
        $result_container = null;
        $exception_container = null;
        $has_exited = false;
        $return_continuation = function ($result, &$result_ref = null, $is_ref = false) use (&$result_container, &$has_exited) {
            if ($has_exited) {
                throw new Exception('Exiting from trampoline a second time. Program will terminate.');
            }
            $has_exited = true;
            $is_ref ? $result_container =& $result_ref : $result_container = $result;
        };    
        $exception_continuation = array(function ($exception) use (&$exception_container, &$is_exited) {
            if ($has_exited) {
                throw new Exception('Exiting from trampoline a second time. Program will terminate.');
            }
            $has_exited = true;
            $exception_container = $exception;
        });
        while ($thunk) {
            $thunk = $thunk($return_continuation, $exception_continuation);
            if ($has_exited) {
                if ($exception_container) {
                    throw $exception_container[0];
                }
                else {
                    return $result_container;
                }
            }
        }
        throw new Exception('Trampoline exited without return value');
    }
    
    static function add_builtin_function($name) {
        self::$function_transforms[$name] = function ($function, $args, $state) {
            return array(new PHPParser_Node_Stmt_TryCatch(
                array(new PHPParser_Node_Stmt_Return(
                    new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Expr_Variable(CONT_NAME), array(
                        new PHPParser_Node_Expr_FuncCall($function, $args)
                    ))
                )),
                array(new PHPParser_Node_Stmt_Catch(
                    new PHPParser_Node_Name('Exception'),
                    VALUE_NAME,
                    array(new PHPParser_Node_Expr_FuncCall(
                        new PHPParser_Node_Expr_ArrayDimFetch(
                            new PHPParser_Node_Expr_Variable(EXCEPT_NAME),
                            new PHPParser_Node_Scalar_LNumber($state->getCatchNum())
                        ),
                        array(new PHPParser_Node_Expr_Variable(VALUE_NAME))
                    ))
                ))
            ));
        };
        
        self::$builtin_functions[$name] = function ($f, $args, $c, $x) {
            try {
                return $c(call_user_func_array($f, $args));
            }
            catch (Exception $e) {
                return $x[0]($e);
            }
        };
    }
    
    public static function add_builtin_method($class, $method) {
        self::$builtin_methods[$class][$method] = true;
    }
}

foreach (get_loaded_extensions() as $extension) {
    foreach (get_extension_funcs($extension) ?: array() as $name) {
        CpsRuntime::add_builtin_function($name);
    }
}

CpsRuntime::$function_transforms['func_get_args'] = function ($function, $args, $state) {
    return array(new PHPParser_Node_Stmt_Return(
        new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Expr_Variable(CONT_NAME), array(
            new PHPParser_Node_Expr_ArrayDimFetch(
                new PHPParser_Node_Expr_Variable(TEMP_NAME),
                new PHPParser_Node_Scalar_String(ARGS_TEMP_NAME)
            )
        ))
    ));
};
//TODOCpsRuntime::$builtin_functions['func_get_args'] = function ($f, $args, $c, $x, $t) {
//    return $c($t['A']);
//};

// Wrap callback parameters to builtins in their own trampolines.
foreach (array(
    'spl_autoload_register' => array(0),
    'array_map' => array(0),
    'preg_replace_callback' => array(1),
    'array_diff_uassoc' => array(-1),
    'array_diff_ukey' => array(-1),
    'array_filter' => array(1),
    'array_intersect_uassoc' => array(-1),
    'array_intersect_ukey' => array(-1),
    'array_reduce' => array(1),
    'array_udiff_assoc' => array(-1),
    'array_udiff_uassoc' => array(-1, -2),
    'array_udiff' => array(-1),
    'array_uintersect_assoc' => array(-1),
    'array_uintersect_uassoc' => array(-1, -2),
    'array_uintersect' => array(-1),
    'array_walk_recursive' => array(1),
    'array_walk' => array(1),
    'uasort' => array(1),
    'uksort' => array(1),
    'usort' => array(1)
    ) as $function_name => $indices)
{
    CpsRuntime::$function_transforms[$function_name] = function ($function, $args, $state) use ($indices) {
        foreach ($indices as $i) {
            if ($i < 0) {
                $i += count($args);
            }
            if (count($args) > $i) {
                $args[$i] = new PHPParser_Node_Arg(wrapFunctionForCallback($args[$i]->value, $state));
            }
            return $GLOBALS['simple_builtin_static_transform']($function, $args, $state);
        }
    };
    
    // TODO CpsRuntime::$builtin_functions version of this - is it possible?
}

$builtin_classes = array('Exception');
foreach ($builtin_classes as $class) {
    $class_obj = new ReflectionClass($class);
    
    $methods = array();
    foreach ($class_obj->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method_obj) {
        $methods[$method_obj->name] = true;
    }
    CpsRuntime::$builtin_methods[$class] = $methods;
}

// normal user function CPS calling convention for dynamic dispatch (could be used for static too at a slight runtime cost)
CpsRuntime::$function_transforms[null] = function ($function, $args, $state) {
    array_unshift($args, $state->generateExceptParameter());
    array_unshift($args, new PHPParser_Node_Expr_Variable(CONT_NAME));
    return array(new PHPParser_Node_Stmt_Return(new PHPParser_Node_Expr_FuncCall($function, $args)));
};

// callcc() function implementation
CpsRuntime::$function_transforms['callcc'] = function ($function, $args, $state) {
    $function = $args[0]->value;
    return generateFunctionCall($function, array(new PHPParser_Node_Arg(
        new PHPParser_Node_Expr_Closure(array(
            'params' => array(
                new PHPParser_Node_Param('j'),
                new PHPParser_Node_Param('j'),
                new PHPParser_Node_Param('v', new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name('null')))
            ),
            'uses' => array(
                new PHPParser_Node_Expr_ClosureUse(CONT_NAME)
            ),
            'stmts' => array(new PHPParser_Node_Stmt_Return(
                new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Expr_Variable(CONT_NAME), array(
                    new PHPParser_Node_Arg(new PHPParser_Node_Expr_Variable('v'))
                ))
            ))
        ))
    )), $state);
};
CpsRuntime::$builtin_functions['callcc'] = function ($f, $args, $c, $x) { // allows dynamic call to callcc. crazy, right?
    return $args[0]($c, $x, function ($jc, $jx, $v = null) use ($c) {
        return $c($v);
    });
};

CpsRuntime::$function_transforms['call_user_func'] = function ($function, $args, $state) {
    $function = array_shift($args)->value;
    return generateFunctionCall($function, $args, $state);
};
// TODO This might permit pass by reference, which the real call_user_func (sadly) does not. If so, we might have to tweak it to match behavior of PHP.
CpsRuntime::$builtin_functions['call_user_func'] = function ($f, $args, $c, $x) {
    $f = array_shift($args);
    array_unshift($args, $x);
    array_unshift($args, $c);
    return call_user_func_array($f, $args);
};

CpsRuntime::$function_transforms['call_user_func_array'] = function ($function, $args, $state) {
    $function = array_shift($args)->value;
    $args = array_shift($args)->value;
    
    $stmts = array();
    $args = assignToTemp($args, $stmts);
    $stmts[] = new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('array_unshift'), array(
        $args, $state->generateExceptParameter()
    ));
    $stmts[] = new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('array_unshift'), array(
        $args, new PHPParser_Node_Expr_Variable(CONT_NAME)
    ));
    
    $stmts[] = new PHPParser_Node_Stmt_Return(
        new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('call_user_func_array'), array(
            $function, $args
        ))
    );
    return $stmts;
};
CpsRuntime::$builtin_functions['call_user_func_array'] = function ($f, $args, $c, $x) {
    $f = array_shift($args);
    $args = array_shift($args);
    array_unshift($args, $x);
    array_unshift($args, $c);
    return call_user_func_array($f, $args);
};