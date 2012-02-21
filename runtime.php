<?php

function _cps_include($file, $compiler, $is_once, $is_require, $from_file, $from_line) {
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
        $function_name = $function_name = 'require' . ($is_once ? '_once' : '');
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

foreach (get_loaded_extensions() as $extension) {
    foreach (get_extension_funcs($extension) ?: array() as $name) {
        $STATIC_FUNCTION_TRANSFORM[$name] = $simple_builtin_static_transform;
        $BUILTIN_FUNCTIONS[$name] = $simple_builtin;
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
//$BUILTIN_FUNCTIONS['func_get_args'] = function ($f, $args, $c, $x, $t) {
//    return $c($t['A']);
//};

$builtin_classes = array('Exception');
foreach ($builtin_classes as $class) {
    // TODO - do something for builtin classes
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
    return $args[0]($c, $x, function ($j, $j, $v) use ($c) {
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
