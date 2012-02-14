<?php

function _cps_include($file, $compiler, $is_once, $is_require) {
    static $includes = array();
    
    if (!is_readable($file)) {
        foreach (explode(PATH_SEPARATOR, get_include_path()) as $path) {
            while (substr($path, -1) == '/') {
                $path = substr($path, 0, strlen($path) - 1);
            }
            $candidate = "$path/$file";
            if (is_readable($candidate)) {
                $file = $candidate;
                break;
            }
        }
    }
    $file = realpath($file);
    
    if (array_key_exists($file, $includes)) {
        if ($is_once) {
            return '';
        }
        else {
            return $includes[$file];
        }
    }
    else {
        if (is_readable($file)) {
            $compiled = `$compiler include $file < $file`;
            // TODO check for compile error
            return $includes[$file] = $compiled;
        }
        else {
            // TODO error out
            return '';
        }
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
    $args = array_map(function ($arg) {
        return new PHPParser_Node_Arg($arg);
    }, array_shift($args)->value);
    return generateFunctionCall($function, $args, $state);
};
$BUILTIN_FUNCTIONS['call_user_func_array'] = function ($f, $args, $c, $x) {
    $f = array_shift($args);
    $args = array_shift($args);
    array_unshift($args, $x);
    array_unshift($args, $c);
    return call_user_func_array($f, $args);
};
