<?php

// for each static method in HttpSapi, create a builtin that calls that function

foreach (array(
    'header',
    'header_remove',
    'headers_list',
    'headers_sent',
    'setcookie',
    'setrawcookie',
    'php_sapi_name'
    ) as $name)
{
    $GLOBALS['STATIC_FUNCTION_TRANSFORM'][$name] = function ($function, $args, $state) {
        array_unshift($args, $state->generateExceptParameter());
        array_unshift($args, new PHPParser_Node_Expr_Variable(CONT_NAME));
        return array(new PHPParser_Node_Stmt_Return(new PHPParser_Node_Expr_StaticCall(new PHPParser_Node_Name('HttpSapi'), $function, $args)));
    };
    
    $GLOBALS['BUILTIN_FUNCTIONS'][$name] = function ($f, $args, $c, $x) {
        array_unshift($args, $x);
        array_unshift($args, $c);
        return call_user_func_array(array('HttpSapi', $f), $args);
    };
}