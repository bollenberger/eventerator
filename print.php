<?php

function getPrecedence($node_type) {
    static $precedence_map = array(
        'Expr_BitwiseNot'       =>  1,
        'Expr_PreInc'           =>  1,
        'Expr_PreDec'           =>  1,
        'Expr_PostInc'          =>  1,
        'Expr_PostDec'          =>  1,
        'Expr_UnaryPlus'        =>  1,
        'Expr_UnaryMinus'       =>  1,
        'Expr_Cast_Int'         =>  1,
        'Expr_Cast_Double'      =>  1,
        'Expr_Cast_String'      =>  1,
        'Expr_Cast_Array'       =>  1,
        'Expr_Cast_Object'      =>  1,
        'Expr_Cast_Bool'        =>  1,
        'Expr_Cast_Unset'       =>  1,
        'Expr_ErrorSuppress'    =>  1,
        'Expr_Instanceof'       =>  2,
        'Expr_BooleanNot'       =>  3,
        'Expr_Mul'              =>  4,
        'Expr_Div'              =>  4,
        'Expr_Mod'              =>  4,
        'Expr_Plus'             =>  5,
        'Expr_Minus'            =>  5,
        'Expr_Concat'           =>  5,
        'Expr_ShiftLeft'        =>  6,
        'Expr_ShiftRight'       =>  6,
        'Expr_Smaller'          =>  7,
        'Expr_SmallerOrEqual'   =>  7,
        'Expr_Greater'          =>  7,
        'Expr_GreaterOrEqual'   =>  7,
        'Expr_Equal'            =>  8,
        'Expr_NotEqual'         =>  8,
        'Expr_Identical'        =>  8,
        'Expr_NotIdentical'     =>  8,
        'Expr_BitwiseAnd'       =>  9,
        'Expr_BitwiseXor'       => 10,
        'Expr_BitwiseOr'        => 11,
        'Expr_BooleanAnd'       => 12,
        'Expr_BooleanOr'        => 13,
        'Expr_Ternary'          => 14,
        'Expr_Assign'           => 15,
        'Expr_AssignPlus'       => 15,
        'Expr_AssignMinus'      => 15,
        'Expr_AssignMul'        => 15,
        'Expr_AssignDiv'        => 15,
        'Expr_AssignConcat'     => 15,
        'Expr_AssignMod'        => 15,
        'Expr_AssignBitwiseAnd' => 15,
        'Expr_AssignBitwiseOr'  => 15,
        'Expr_AssignBitwiseXor' => 15,
        'Expr_AssignShiftLeft'  => 15,
        'Expr_AssignShiftRight' => 15,
        'Expr_AssignList'       => 15,
        'Expr_LogicalAnd'       => 16,
        'Expr_LogicalXor'       => 17,
        'Expr_LogicalOr'        => 18,
    );
    
    return array_key_exists($node_type, $precedence_map) ? $precedence_map[$node_type] : null;
}

function printStatements($stmts) {
    foreach ($stmts as $stmt) {
        printNode($stmt);
        if ($stmt instanceof PHPParser_Node_Expr) {
            echo ';';
        }
        echo "\n";
    }
}

function printModifiers($modifiers) {
    echo ($modifiers & PHPParser_Node_Stmt_Class::MODIFIER_PUBLIC    ? 'public '    : '')
        . ($modifiers & PHPParser_Node_Stmt_Class::MODIFIER_PROTECTED ? 'protected ' : '')
        . ($modifiers & PHPParser_Node_Stmt_Class::MODIFIER_PRIVATE   ? 'private '   : '')
        . ($modifiers & PHPParser_Node_Stmt_Class::MODIFIER_STATIC    ? 'static '    : '')
        . ($modifiers & PHPParser_Node_Stmt_Class::MODIFIER_ABSTRACT  ? 'abstract '  : '')
        . ($modifiers & PHPParser_Node_Stmt_Class::MODIFIER_FINAL     ? 'final '     : '');
}

function printEncapsedList($list, $quote) {
    foreach ($list as $element) {
        if (is_string($element)) {
            echo addcslashes($element, "\n\r\t\f\v$" . $quote . "\\");
        } else {
            echo '{';
            printNode($element);
            echo '}';
        }
    }
}

function printImplode($nodes, $sep = '') {
    $is_first = true;
    foreach ($nodes as $node) {
        if ($is_first) {
            $is_first = false;
        }
        else {
            echo $sep;
        }
        printNode($node);
    }
}

function printCommaSeparated($nodes) {
    printImplode($nodes, ',');
}

function printVarOrNewExpr($node) {
    if ($node instanceof PHPParser_Node_Expr_New) {
        echo '(';
        printNode($node);
        echo ')';
    } else {
        echo printNode($node);
    }
}

function printObjectProperty($node) {
    if ($node instanceof PHPParser_Node_Expr) {
        echo '{';
        printNode($node);
        echo '}';
    } else {
        echo $node; // is a string?
    }
}

function printAssignList($vars) {
    echo 'list(';
    $is_first = true;
    foreach ($vars as $var) {
        if ($is_first) {
            $is_first = false;
        }
        else {
            echo ',';
        }
        if (null === $var) {
            // emit nothing
        }
        elseif (is_array($var)) {
            printAssignList($var);
        }
        else {
            printNode($var);
        }
    }
    echo ')';
}

function printNode($node, $precedence = null) {
    if (!isset($precedence)) {
        $precedence = 19;
    }

    if (!is_object($node)) {
        print_r($node);
        throw new Exception('non-object '/* . print_r($node, true)*/);
    }
    $type = $node->getType();
    $node_precedence = getPrecedence($type);
    
    if ($node_precedence && $node_precedence > $precedence) {
        echo '(';
        printNode($node, $node_precedence);
        echo ')';
        return;
    }
    else {
        switch ($type) {
            case 'Param':
                echo ($node->type ? (is_string($node->type) ? $node->type : $this->p($node->type)) . ' ' : '') . ($node->byRef ? '&' : '') . '$' . $node->name;
                if ($node->default) {
                    echo '=';
                    printNode($node->default);
                }
                break;
            case 'Arg':
                echo $node->byRef ? '&' : '';
                printNode($node->value);
                break;
            case 'Const':
                echo $node->name . '=';
                printNode($node->value);
                break;
            case 'Name':
                echo implode('\\', $node->parts);
                break;
            case 'Name_FullyQualified':
                echo '\\' . implode('\\', $node->parts);
                break;
            case 'Name_Relative':
                echo 'namespace\\' . implode('\\', $node->parts);
                break;
            case 'Scalar_ClassConst':
                echo '__CLASS__';
                break;
            case 'Scalar_TraitConst':
                echo '__TRAIT__';
                break;
            case 'Scalar_DirConst':
                echo '__DIR__';
                break;
            case 'Scalar_FileConst':
                echo '__FILE__';
                break;
            case 'Scalar_FunctionConst':
                echo '__FUNCTION__';
                break;
            case 'Scalar_LineConst':
                echo '__LINE__';
                break;
            case 'Scalar_MethodConst':
                echo '__METHOD__';
                break;
            case 'Scalar_NamespaceConst':
                echo '__NAMESPACE__';
                break;
            case 'Scalar_String':
                echo '\'' . addcslashes($node->value, '\'\\') . '\'';
                break;
            case 'Scalar_Encapsed':
                echo '"';
                printEncapsedList($node->parts, '"');
                echo '"';
                break;
            case 'Scalar_LNumber':
                echo $node->value;
                break;
            case 'Scalar_DNumber':
                $stringValue = (string) $node->value;
                echo ctype_digit($stringValue) ? $stringValue . '.0' : $stringValue;
                break;
            case 'Expr_Assign':
                printNode($node->var, $node_precedence);
                echo '=';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_AssignRef':
                printNode($node->var, $node_precedence);
                echo '=&';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_AssignPlus':
                printNode($node->var, $node_precedence);
                echo '+=';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_AssignMinus':
                printNode($node->var, $node_precedence);
                echo '-=';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_AssignMul':
                printNode($node->var, $node_precedence);
                echo '*=';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_AssignDiv':
                printNode($node->var, $node_precedence);
                echo '/=';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_AssignConcat':
                printNode($node->var, $node_precedence);
                echo '.=';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_AssignMod':
                printNode($node->var, $node_precedence);
                echo '%=';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_AssignBitwiseAnd':
                printNode($node->var, $node_precedence);
                echo '&=';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_AssignBitwiseOr':
                printNode($node->var, $node_precedence);
                echo '|=';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_AssignBitwiseXor':
                printNode($node->var, $node_precedence);
                echo '^=';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_AssignShiftLeft':
                printNode($node->var, $node_precedence);
                echo '<<=';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_AssignShiftRight':
                printNode($node->var, $node_precedence);
                echo '>>=';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_AssignList':
                printAssignList($node->vars, $node_precedence);
                echo '=';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_Plus':
                printNode($node->left, $node_precedence);
                echo '+';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_Minus':
                printNode($node->left, $node_precedence);
                echo '-';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_Mul':
                printNode($node->left, $node_precedence);
                echo '*';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_Div':
                printNode($node->left, $node_precedence);
                echo '/';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_Concat':
                printNode($node->left, $node_precedence);
                echo '.';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_Mod':
                printNode($node->left, $node_precedence);
                echo '%';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_BooleanAnd':
                printNode($node->left, $node_precedence);
                echo '&&';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_BooleanOr':
                printNode($node->left, $node_precedence);
                echo '||';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_BitwiseAnd':
                printNode($node->left, $node_precedence);
                echo '&';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_BitwiseOr':
                printNode($node->left, $node_precedence);
                echo '|';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_BitwiseXor':
                printNode($node->left, $node_precedence);
                echo '^';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_ShiftLeft':
                printNode($node->left, $node_precedence);
                echo '<<';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_ShiftRight':
                printNode($node->left, $node_precedence);
                echo '>>';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_LogicalAnd':
                printNode($node->left, $node_precedence);
                echo ' and ';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_LogicalOr':
                printNode($node->left, $node_precedence);
                echo ' or ';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_LogicalXor':
                printNode($node->left, $node_precedence);
                echo ' xor ';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_Equal':
                printNode($node->left, $node_precedence);
                echo '==';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_NotEqual':
                printNode($node->left, $node_precedence);
                echo '!=';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_Identical':
                printNode($node->left, $node_precedence);
                echo '===';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_NotIdentical':
                printNode($node->left, $node_precedence);
                echo '!==';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_Greater':
                printNode($node->left, $node_precedence);
                echo '>';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_GreaterOrEqual':
                printNode($node->left, $node_precedence);
                echo '>=';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_Smaller':
                printNode($node->left, $node_precedence);
                echo '<';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_SmallerOrEqual':
                printNode($node->left, $node_precedence);
                echo '<=';
                printNode($node->right, $node_precedence);
                break;
            case 'Expr_Instanceof':
                printNode($node->expr, $node_precedence);
                echo ' instanceof ';
                printNode($node->class, $node_precedence);
                break;
            case 'Expr_BooleanNot':
                echo '!';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_BitwiseNot':
                echo '~';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_UnaryMinus':
                echo '-';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_UnaryPlus':
                echo '+';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_PreInc':
                echo '++';
                printNode($node->var, $node_precedence);
                break;
            case 'Expr_PreDec':
                echo '--';
                printNode($node->var, $node_precedence);
                break;
            case 'Expr_PostInc':
                printNode($node->var, $node_precedence);
                echo '++';
                break;
            case 'Expr_PostDec':
                printNode($node->var, $node_precedence);
                echo '--';
                break;
            case 'Expr_ErrorSuppress':
                echo '@';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_Cast_Int':
                echo '(int)';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_Cast_Double':
                echo '(double)';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_Cast_String':
                echo '(string)';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_Cast_Array':
                echo '(array)';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_Cast_Object':
                echo '(object)';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_Cast_Bool':
                echo '(bool)';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_Cast_Unset':
                echo '(unset)';
                printNode($node->expr, $node_precedence);
                break;
            case 'Expr_FuncCall':
                printNode($node->name);
                echo '(';
                printCommaSeparated($node->args);
                echo ')';
                break;
            case 'Expr_MethodCall':
                printVarOrNewExpr($node->var);
                echo '->';
                printObjectProperty($node->name);
                echo '(';
                printCommaSeparated($node->args);
                echo ')';
                break;
            case 'Expr_StaticCall':
                printNode($node->class);
                echo '::';
                if ($node->name instanceof PHPParser_Node_Expr) {
                    if ($node->name instanceof PHPParser_Node_Expr_Variable ||
                        $node->name instanceof PHPParser_Node_Expr_ArrayDimFetch)
                    {
                        printNode($node->name);
                    }
                    else {
                        echo '{';
                        printNode($node->name);
                        echo '}';
                    }
                }
                else {
                    echo $node->name;
                }
                echo '(';
                printCommaSeparated($node->args);
                echo ')';
                break;
            case 'Expr_Empty':
                echo 'empty(';
                printNode($node->var);
                echo ')';
                break;
            case 'Expr_Isset':
                echo 'isset(';
                printCommaSeparated($node->vars);
                echo ')';
                break;
            case 'Expr_Print':
                echo 'print ';
                printNode($node->expr);
                break;
            case 'Expr_Eval':
                echo 'eval(';
                printNode($node->expr);
                echo ')';
                break;
            case 'Expr_Include':
                static $include_map = array(
                    PHPParser_Node_Expr_Include::TYPE_INCLUDE      => 'include',
                    PHPParser_Node_Expr_Include::TYPE_INCLUDE_ONCE => 'include_once',
                    PHPParser_Node_Expr_Include::TYPE_REQUIRE      => 'require',
                    PHPParser_Node_Expr_Include::TYPE_REQUIRE_ONCE => 'require_once',
                );

                echo $include_map[$node->type] . ' ';
                printNode($node->expr);
                break;
            case 'Expr_Variable':
                if ($node->name instanceof PHPParser_Node_Expr) {
                    echo '${';
                    printNode($node->name);
                    echo '}';
                }
                else {
                    echo '$' . $node->name;
                }
                break;
            case 'Expr_Array':
                echo 'array(';
                printCommaSeparated($node->items);
                echo ')';
                break;
            case 'Expr_ArrayItem':
                if (null !== $node->key) {
                    printNode($node->key);
                    echo ' => ';
                }
                echo $node->byRef ? '&' : '';
                printNode($node->value);
                break;
            case 'Expr_ArrayDimFetch':
                printVarOrNewExpr($node->var);
                echo '[';
                if (null !== $node->dim) {
                    printNode($node->dim);
                }
                echo ']';
                break;
            case 'Expr_ConstFetch':
                printNode($node->name);
                break;
            case 'Expr_ClassConstFetch':
                printNode($node->class);
                echo '::' . $node->name;
                break;
            case 'Expr_PropertyFetch':
                printVarOrNewExpr($node->var);
                echo '->';
                printObjectProperty($node->name);
                break;
            case 'Expr_StaticPropertyFetch':
                printNode($node->class);
                echo '::$';
                printObjectProperty($node->name);
                break;
            case 'Expr_ShellExec':
                echo '`';
                printEncapsedList($node->parts, '`');
                echo '`';
                break;
            case 'Expr_Closure':
                echo ($node->static ? 'static ' : '') . 'function' . ($node->byRef ? '&' : '') . '(';
                printCommaSeparated($node->params);
                echo ')';
                if (!empty($node->uses)) {
                    echo 'use(';
                    printCommaSeparated($node->uses);
                    echo ')';
                }
                echo "{\n";
                printStatements($node->stmts);
                echo '}';
                break;
            case 'Expr_ClosureUse':
                echo ($node->byRef ? '&' : '') . '$' . $node->var;
                break;
            case 'Expr_New':
                echo 'new ';
                printNode($node->class);
                echo '(';
                printCommaSeparated($node->args);
                echo ')';
                break;
            case 'Expr_Clone':
                echo 'clone ';
                printNode($node->expr);
                break;
            case 'Expr_Ternary':
                printNode($node->cond, $node_precedence);
                echo '?';
                if (null !== $node->if) {
                    printNode($node->if, $node_precedence);
                }
                echo ':';
                printNode($node->else, $node_precedence);
                break;
            case 'Expr_Exit':
                echo 'die';
                if (null !== $node->expr) {
                    echo '(';
                    printNode($node->expr);
                    echo ')';
                }
                break;
            case 'Stmt_Namespace':
                echo 'namespace';
                if (null !== $node->name) {
                    echo ' ';
                    printNode($node->name);
                }
                echo " {\n";
                printStatements($node->stmts);
                echo '}';
                break;
            case 'Stmt_Use':
                echo 'use ';
                printCommaSeparated($node->uses);
                echo ';';
                break;
            case 'Stmt_UseUse':
                printNode($node->name);
                if ($node->name->getLast() !== $node->alias) {
                    echo ' as ' . $node->alias;
                }
                break;
            case 'Stmt_Interface':
                echo 'interface ' . $node->name;
                if (!empty($node->extends)) {
                    echo ' extends ';
                    printCommaSeparated($node->extends);
                }
                echo "{\n";
                printStatements($node->stmts);
                echo '}';
                break;
            case 'Stmt_Class':
                printModifiers($node->type);
                echo 'class ' . $node->name;
                if (null !== $node->extends) {
                    echo ' extends ';
                    printNode($node->extends);
                }
                if (!empty($node->implements)) {
                    echo ' implements ';
                    printCommaSeparated($node->implements);
                }
                echo "{\n";
                printStatements($node->stmts);
                echo '}';
                break;
            case 'Stmt_Trait':
                echo 'trait ' . $node->name . "{\n";
                printStatements($node->stmts);
                echo '}';
                break;
            case 'Stmt_TraitUse':
                echo 'use ';
                printCommaSeparated($node->traits);
                if (empty($node->adaptations)) {
                    echo ';';
                }
                else {
                    echo " {\n";
                    printStatements($node->adaptations);
                    echo '}';
                }
                break;
            case 'Stmt_TraitUseAdaptation_Precedence':
                printNode($node->trait);
                echo '::' . $node->method . ' insteadof ';
                printCommaSeparated($node->insteadof);
                echo ';';
                break;
            case 'Stmt_TraitUseAdaptation_Alias':
                if (null !== $node->trait) {
                    printNode($node->trait);
                    echo '::';
                }
                echo $node->method . ' as';
                if (null !== $node->newModifier) {
                    echo ' ';
                    printModifiers($node->newModifier);
                }
                if (null !== $node->newName) {
                    echo ' ' . $node->newName;
                }
                echo ';';
                break;
            case 'Stmt_Property':
                printModifiers($node->type);
                printCommaSeparated($node->props);
                echo ';';
                break;
            case 'Stmt_PropertyProperty':
                echo '$' . $node->name;
                if (null !== $node->default) {
                    echo ' = ';
                    printNode($node->default);
                }
                break;
            case 'Stmt_ClassMethod':
                printModifiers($node->type);
                echo 'function ' . ($node->byRef ? '&' : '') . $node->name . '(';
                printCommaSeparated($node->params);
                echo ')';
                if (null !== $node->stmts) {
                    echo "{\n";
                    printStatements($node->stmts);
                    echo '}';
                }
                else {
                    echo ';';
                }
                break;
            case 'Stmt_ClassConst':
                echo 'const ';
                printCommaSeparated($node->consts);
                echo ';';
                break;
            case 'Stmt_Function':
                echo 'function ' . ($node->byRef ? '&' : '') . $node->name . '(';
                printCommaSeparated($node->params);
                echo ") {\n";
                printStatements($node->stmts);
                echo '}';
                break;
            case 'Stmt_Const':
                echo 'const ';
                printCommaSeparated($node->consts);
                echo ';';
                break;
            case 'Stmt_Declare':
                echo 'declare (';
                printCommaSeparated($node->declares);
                echo ") {\n";
                printStatements($node->stmts);
                echo '}';
                break;
            case 'Stmt_DeclareDeclare':
                echo $node->key . ' = ';
                printNode($node->value);
                break;
            case 'Stmt_If':
                echo 'if(';
                printNode($node->cond);
                echo "){\n";
                printStatements($node->stmts);
                echo '}';
                printImplode($node->elseifs);
                if (null !== $node->else) {
                    printNode($node->else);
                }
                break;
            case 'Stmt_Elseif':
                echo 'elseif(';
                printNode($node->cond);
                echo "){\n";
                printStatements($node->stmts);
                echo '}';
                break;
            case 'Stmt_Else':
                echo "else{\n";
                printStatements($node->stmts);
                echo '}';
                break;
            case 'Stmt_For':
                echo 'for(';
                printCommaSeparated($node->init);
                echo ';';
                printCommaSeparated($node->cond);
                echo ';';
                printCommaSeparated($node->loop);
                echo ") {\n";
                printStatements($node->stmts);
                echo '}';
                break;
            case 'Stmt_Foreach':
                echo 'foreach(';
                printNode($node->expr);
                echo ' as ';
                if (null !== $node->keyVar) {
                    printNode($node->keyVar);
                    echo ' => ';
                }
                echo $node->byRef ? '&' : '';
                printNode($node->valueVar);
                echo ") {\n";
                printStatements($node->stmts);
                echo '}';
                break;
            case 'Stmt_While':
                echo 'while (';
                printNode($node->cond);
                echo ") {\n";
                printStatements($node->stmts);
                echo '}';
                break;
            case 'Stmt_Do':
                echo "do{\n";
                printStatements($node->stmts);
                echo '}while(';
                printNode($node->cond);
                echo ');';
                break;
            case 'Stmt_Switch':
                echo 'switch(';
                printNode($node->cond);
                echo "){\n";
                printImplode($node->cases);
                echo '}';
                break;
            case 'Stmt_Case':
                if (null !== $node->cond) {
                    echo 'case ';
                    printNode($node->cond);
                }
                else {
                    echo 'default';
                }
                echo ":\n";
                printStatements($node->stmts);
                break;
            case 'Stmt_TryCatch':
                echo "try{\n";
                printStatements($node->stmts);
                echo '}';
                printImplode($node->catches);
                break;
            case 'Stmt_Catch':
                echo 'catch(';
                printNode($node->type);
                echo ' $' . $node->var . "){\n";
                printStatements($node->stmts);
                echo '}';
                break;
            case 'Stmt_Break':
                echo 'break';
                if ($node->num !== null) {
                    echo ' ';
                    printNode($node->num);
                }
                echo ';';
                break;
            case 'Stmt_Continue':
                echo 'continue';
                if ($node->num !== null) {
                    echo ' ';
                    printNode($node->num);
                }
                echo ';';
                break;
            case 'Stmt_Return':
                echo 'return';
                if (null !== $node->expr) {
                    echo ' ';
                    printNode($node->expr);
                }
                echo ';';
                break;
            case 'Stmt_Throw':
                echo 'throw ';
                printNode($node->expr);
                echo ';';
                break;
            case 'Stmt_Label':
                echo $node->name . ':';
                break;
            case 'Stmt_Goto':
                echo 'goto ' . $node->name . ';';
                break;
            case 'Stmt_Echo':
                echo 'echo ';
                printCommaSeparated($node->exprs);
                echo ';';
                break;
            case 'Stmt_Static':
                echo 'static ';
                printCommaSeparated($node->vars);
                echo ';';
                break;
            case 'Stmt_Global':
                echo 'global ';
                printCommaSeparated($node->vars);
                echo ';';
                break;
            case 'Stmt_StaticVar':
                echo '$' . $node->name;
                if (null !== $node->default) {
                    echo ' = ';
                    printNode($node->default);
                }
                break;
            case 'Stmt_Unset':
                echo 'unset(';
                printCommaSeparated($node->vars);
                echo ');';
                break;
            case 'Stmt_InlineHTML':
                echo '?>';
                if ("\n" === $node->value[0] || "\r" === $node->value[0]) {
                    echo "\n";
                }
                echo $node->value . '<?php ';
                break;
            case 'Stmt_HaltCompiler':
                echo '__halt_compiler();' . $node->remaining;
                break;
            
            default:
                throw new Exception('Unknown node type ' . $type . ' for printing');
                break;
        }
    }
}