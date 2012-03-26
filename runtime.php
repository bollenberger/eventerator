<?php

function _cps_include($file, $compiler, $is_require) {
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

function _cps_include_eval($file, $compiler, $is_once, $is_require, $from_file, $from_line) {
    static $includes = array();
    
    $original_file = $file;
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
    
    if ($file && array_key_exists($file, $includes)) {
        if ($is_once) {
            return 'return function () use ($c) { return $c(true); };';
        }
        else {
            return $includes[$file];
        }
    }
    else {
        $function_name = 'require' . ($is_once ? '_once' : '');
        if (is_readable($file)) {
            exec("$compiler include $file < $file", $output, $return_value);
            $output[] = '';
            $output = implode("\n", $output);
            if ($return_value == 0) { // if there was no compile error
                return $includes[$file] = $output;
            }
            else {
                $error = $output;
            }
        }
        else {
            $error = "\nFatal error: $function_name(): Failed opening required '$original_file' (include_path='$include_path') in $from_file on line $from_line\n";
        }
    }
    
    if ($is_require) {
        echo $error;
        exit(255);
    }
    else {
        return 'return function () use ($c) { return $c(false); };';
    }
}

function &_cps_trampoline($thunk) {
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

// Simple pass by value builtin functions
$simple_builtin_static_transform = function ($function, $args, $state) {
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
$simple_builtin = function ($f, $args, $c, $x) {
    try {
        return $c(call_user_func_array($f, $args));
    }
    catch (Exception $e) {
        return $x[0]($e);
    }
};

function __cps_add_builtin($name) {
    global $STATIC_FUNCTION_TRANSFORM, $BUILTIN_FUNCTIONS, $simple_builtin_static_transform, $simple_builtin;
    $STATIC_FUNCTION_TRANSFORM[$name] = $simple_builtin_static_transform;
    $BUILTIN_FUNCTIONS[$name] = $simple_builtin;
}

foreach (get_loaded_extensions() as $extension) {
    foreach (get_extension_funcs($extension) ?: array() as $name) {
/*foreach (array(
'ob_implicit_flush',
'ob_start',
'str_repeat',
'array_fill',
'substr',
'strlen',
'print'
) as $name) {*/
        __cps_add_builtin($name);
    }
}

$STATIC_FUNCTION_TRANSFORM['func_get_args'] = function ($function, $args, $state) {
    return array(new PHPParser_Node_Stmt_Return(
        new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Expr_Variable(CONT_NAME), array(
            new PHPParser_Node_Expr_ArrayDimFetch(
                new PHPParser_Node_Expr_Variable(TEMP_NAME),
                new PHPParser_Node_Scalar_String(ARGS_TEMP_NAME)
            )
        ))
    ));
};
//TODO$BUILTIN_FUNCTIONS['func_get_args'] = function ($f, $args, $c, $x, $t) {
//    return $c($t['A']);
//};

foreach (array(
    'spl_autoload_register' => array(0),
    'array_map' => array(0),
    'preg_replace_callback' => array(1)
    ) as $function_name => $indices)
{
    $STATIC_FUNCTION_TRANSFORM[$function_name] = function ($function, $args, $state) use ($indices) {
        foreach ($indices as $i) {
            if (count($args) > $i) {
                $args[$i] = new PHPParser_Node_Arg(wrapFunctionForCallback($args[$i]->value, $state));
            }
            return $GLOBALS['simple_builtin_static_transform']($function, $args, $state);
        }
    };
}

function __cps_add_builtin_method($class, $method) {
    global $CLASS_TO_BUILTIN_METHODS;
    $CLASS_TO_BUILTIN_METHODS[$class][$method] = true;
}
$CLASS_TO_BUILTIN_METHODS = array();
$builtin_classes = array('Exception');
foreach ($builtin_classes as $class) {
    $class_obj = new ReflectionClass($class);
    
    $methods = array();
    foreach ($class_obj->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method_obj) {
        $methods[$method_obj->name] = true;
    }
    $CLASS_TO_BUILTIN_METHODS[$class] = $methods;
}

// normal user function CPS calling convention for dynamic dispatch (could be used for static too at a slight runtime cost)
$STATIC_FUNCTION_TRANSFORM[null] = function ($function, $args, $state) {
    array_unshift($args, $state->generateExceptParameter());
    array_unshift($args, new PHPParser_Node_Expr_Variable(CONT_NAME));
    return array(new PHPParser_Node_Stmt_Return(new PHPParser_Node_Expr_FuncCall($function, $args)));
};

// callcc() function implementation
$STATIC_FUNCTION_TRANSFORM['callcc'] = function ($function, $args, $state) {
    $function = $args[0]->value;
    return generateFunctionCall($function, array(new PHPParser_Node_Arg(
        new PHPParser_Node_Expr_Closure(array(
            'params' => array(
                new PHPParser_Node_Param('j'),
                new PHPParser_Node_Param('j'),
                new PHPParser_Node_Param('v')
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
$BUILTIN_FUNCTIONS['callcc'] = function ($f, $args, $c, $x) { // allows dynamic call to callcc. crazy, right?
    return $args[0]($c, $x, function ($jc, $jx, $v) use ($c) {
        return $c($v);
    });
};

$STATIC_FUNCTION_TRANSFORM['call_user_func'] = function ($function, $args, $state) {
    $function = array_shift($args)->value;
    return generateFunctionCall($function, $args, $state);
};
// TODO This might permit pass by reference, which the real call_user_func (sadly) does not. If so, we might have to tweak it to match behavior of PHP.
$BUILTIN_FUNCTIONS['call_user_func'] = function ($f, $args, $c, $x) {
    $f = array_shift($args);
    array_unshift($args, $x);
    array_unshift($args, $c);
    return call_user_func_array($f, $args);
};

$STATIC_FUNCTION_TRANSFORM['call_user_func_array'] = function ($function, $args, $state) {
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
$BUILTIN_FUNCTIONS['call_user_func_array'] = function ($f, $args, $c, $x) {
    $f = array_shift($args);
    $args = array_shift($args);
    array_unshift($args, $x);
    array_unshift($args, $c);
    return call_user_func_array($f, $args);
};
