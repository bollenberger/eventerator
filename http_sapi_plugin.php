<?php

// for each static method in HttpSapi, create a builtin that calls that function

foreach (array(
    'headers'
    ) as $name)
{
    $GLOBALS['STATIC_FUNCTION_TRANSFORM'][$name] = function ($function, $args, $state) {
        array_unshift($args, $state->generateExceptParameter());
        array_unshift($args, new PHPParser_Node_Expr_Variable(CONT_NAME));
        return array(new PHPParser_Node_Stmt_Return(new PHPParser_Node_Expr_StaticCall(new PHPParser_Node_Name('HttpSapi'), $function, $args)));
    };
}