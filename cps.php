<?php

// TODO
// builtin classes/methods and dynamic method calls
// builtins that take callbacks need to have callbacks wrapped with trampolines
// include/require by file rather than eval (and cache compiled forms)
// line number mappings in generated code (comments)
// traits
// namespaces
// break and continue by non-const number (include array of basic block IDs in compiled code)
// pass down break and continue stack when including to permit break in top level of an included file

// Continuation passing style transformation for PHP

require_once 'PHP-Parser/lib/bootstrap.php';
require_once 'print.php';
require_once 'runtime.php';

const TEMP_NAME = 't';
const GLOBALS_TEMP_NAME = 'G';
const ARGS_TEMP_NAME = 'A';
const CONT_NAME = 'c';
const LOCALS_NAME = 'l';
const LABELS_NAME = 'g';
const VALUE_NAME = 'v';
const VALUE_REF_NAME = 'r';
const VALUE_IS_REF_NAME = 'q';
const JUNK_NAME = 'j';
const PARAM_NAME = 'p';
const USES_NAME = 'u';
const EXCEPT_NAME = 'x';

const STATICS_NAME = 's';
const KEY_NAME = 'k';
$SUPERGLOBALS = array('GLOBALS', '_SERVER', '_GET', '_POST', '_FILES', '_COOKIE', '_SESSION', '_REQUEST', '_ENV');
$MAGIC_METHODS = array('__construct', '__set', '__get', '__destruct', '__sleep', '__wakeup', '__toString', '__isset', '__unset', '__call', '__callStatic', '__set_state', '__clone');

function config($name, $value = null) {
    static $configs = array();
    if (isset($value)) {
        $configs[$name] = $value;
    }
    else {
        if (array_key_exists($name, $configs)) {
            return $configs[$name];
        }
    }
}
config('disable_strict_logical_operator_type', false);
config('disable_func_get_args', false);
config('trampoline_max_stack', 40); // optimum seems to be around 40 or 50

$interpreter = $argv[1];
$compiler = $interpreter . ' ' . __FILE__ . ' "' . $interpreter . '"';
$file = $argv[3];
$source = file_get_contents('php://stdin');
$compiled = compile($source);

switch ($mode = $argv[2]) {
    case 'main':
        echo "<?php\nrequire_once('" . dirname(__FILE__) . "/runtime.php');\n\$l=&\$GLOBALS;\n\$t=array('" . GLOBALS_TEMP_NAME . "'=>&\$GLOBALS);\n\$g=array();\n";
        printStatements(generateTrampoline($compiled));
        echo "\n";
        break;

    case 'include':
        echo "<?php\n";
        printStatements($compiled);
        break;
    
    default:
        throw new Exception('unknown mode ' . $mode);
        break;
}

// A container for a single parser node reference
class NodeReference extends PHPParser_Node_Expr {
    function __construct(PHPParser_Node_Expr $node) {
        $this->node = $node;
    }
    
    function getNode() {
        return $this->node;
    }
    
    function setNode(PHPParser_Node_Expr $node) {
        $this->node = $node;
    }
}

// Wrapper for a closure if it represents a return from a function.
// This is used to determine whether a given continuation represents
// a tail call that can be optimized away.
class ReturnClosure {
    private $closure;

    function __construct($closure) {
        $this->closure = $closure;
    }
    
    function __invoke() {
        return call_user_func_array($this->closure, func_get_args());
    }
}

function generateDefaultTemps() {
    $temps = array();
    $temps[] = new PHPParser_Node_Expr_ArrayItem(
        new PHPParser_Node_Expr_Variable('GLOBALS'),
        new PHPParser_Node_Scalar_String(GLOBALS_TEMP_NAME),
        true
    );
    if (!config('disable_func_get_args')) {
        $temps[] = new PHPParser_Node_Expr_ArrayItem(
            new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('array_slice'), array(
                new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('func_get_args'), array()),
                new PHPParser_Node_Scalar_LNumber(2)
            )),
            new PHPParser_Node_Scalar_String(ARGS_TEMP_NAME)
        );
    }
    return new PHPParser_Node_Expr_Array($temps);
}

function generateTemp() {
    static $temp_counter = 0;
    return new PHPParser_Node_Expr_ArrayDimFetch(
        new PHPParser_Node_Expr_Variable(TEMP_NAME),
        new PHPParser_Node_Scalar_LNumber($temp_counter++)
    );
}

// Assign an expression to a temp or inline simple variable names.
// Appends an assignment statement to $stmts if necessary.
// Returns the name by which the expression will be known.
function assignToTemp($expr, &$stmts, $byRef = false) {
    $temp = generateTemp();
    $assign = $byRef ? 'PHPParser_Node_Expr_AssignRef' : 'PHPParser_Node_Expr_Assign';
    $stmts[] = new $assign($temp, $expr);
    return $temp;
}

// Generate the expression to convert an expression into && compatible values (true, false, 1, 0, depending on value).
function boolifyLogicalOperator($expr) {
    // If one does not depend in a === sense on the result of &&, and, ||, or being true vs. 1 vs. truthy value, then
    // we cut down a bit of bloat by configurably turning this into the identity function.
    if (config('disable_strict_logical_operator_type')) return $expr;
    
    $temp = generateTemp();
    return new PHPParser_Node_Expr_Ternary(
        new PHPParser_Node_Expr_FuncCall(
            new PHPParser_Node_Name('is_bool'),
            array(new PHPParser_Node_Expr_Assign($temp, $expr))
        ),
        $temp,
        new PHPParser_Node_Expr_Ternary(
            $temp,
            new PHPParser_Node_Scalar_LNumber(1),
            new PHPParser_Node_Scalar_LNumber(0)
        )
    );
}

function generateContinuation($next, $cont, $state) {
    if ($next instanceof ReturnClosure) {
        return $cont(new PHPParser_Node_Expr_Variable(CONT_NAME), $state);
    }
    
    $temp = generateTemp();
    return $next($temp, function ($result, $state) use ($cont, $temp) {
        $result = array_merge(array(
            new PHPParser_Node_Expr_Ternary(
                new PHPParser_Node_Expr_Variable(VALUE_IS_REF_NAME),
                new PHPParser_Node_Expr_AssignRef($temp, new PHPParser_Node_Expr_Variable(VALUE_REF_NAME)),
                new PHPParser_Node_Expr_Assign($temp, new PHPParser_Node_Expr_Variable(VALUE_NAME))
            ),
            new PHPParser_Node_Expr_Assign(
                new PHPParser_Node_Expr_Variable(LOCALS_NAME),
                new PHPParser_Node_Expr_Variable(LOCALS_NAME)
            )
        ), $result);
        return $cont(new PHPParser_Node_Expr_Closure(array(
            'params' => array(
                new PHPParser_Node_Param(VALUE_NAME),
                new PHPParser_Node_Param(VALUE_REF_NAME, new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name('null')), null, true),
                new PHPParser_Node_Param(VALUE_IS_REF_NAME, new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name('false')))
            ),
            'uses' => array(
                new PHPParser_Node_Expr_ClosureUse(CONT_NAME),
                new PHPParser_Node_Expr_ClosureUse(EXCEPT_NAME),
                new PHPParser_Node_Expr_ClosureUse(LOCALS_NAME, true),
                new PHPParser_Node_Expr_ClosureUse(TEMP_NAME, true),
                new PHPParser_Node_Expr_ClosureUse(LABELS_NAME, true)
            ),
            'stmts' => $result
        )), $state);
    }, $state);
}

// When using generateContinuation, the $final parameter for the first traverseNode will
// likely need to be the result of this function.
function generateFinalForContinuation($final, $continuation) {
    return function ($result, $state) use ($final, $continuation) {
        if (!($continuation instanceof PHPParser_Node_Expr_Variable) || $continuation->name != CONT_NAME) {
            array_unshift($result, new PHPParser_Node_Expr_Assign(
                new PHPParser_Node_Expr_Variable(CONT_NAME),
                $continuation
            ));
        }
        return $final($result, $state);
    };
}

function isLValue($expr) {
    return ($expr instanceof PHPParser_Node_Expr_Variable ||
        $expr instanceof PHPParser_Node_Expr_ArrayDimFetch ||
        $expr instanceof PHPParser_Node_Expr_FuncCall ||
        $expr instanceof PHPParser_Node_Expr_MethodCall ||
        $expr instanceof PHPParser_Node_Expr_PropertyFetch ||
        $expr instanceof PHPParser_Node_Expr_StaticCall ||
        $expr instanceof PHPParser_Node_Expr_StaticPropertyFetch);
}

// When you need to call the current continuation with a return value, this function
// will generate the return/call to emit.
function generateReturn($value, $state) {
    if (!isset($value)) {
        $value = new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name('null'));
    }
    
    $is_return_by_ref = $state ? $state->getIsReturnByRef() : false;
    $is_return_by_ref &= isLValue($value); // Don't try to return rvalue by ref. Simulates PHP behavior on return by reference.
    
    return array(generateThunk(
        array(
            new PHPParser_Node_Expr_ClosureUse(CONT_NAME),
            new PHPParser_Node_Expr_ClosureUse(LOCALS_NAME, true),
            new PHPParser_Node_Expr_ClosureUse(TEMP_NAME, true)
        ),
        array(new PHPParser_Node_Stmt_Return(
            new PHPParser_Node_Expr_FuncCall(
                new PHPParser_Node_Expr_Variable(CONT_NAME),
                $is_return_by_ref ?
                    array(
                        new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name('null')),
                        new PHPParser_Node_Arg($value),
                        new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name('true'))
                    )
                    :
                    array(new PHPParser_Node_Arg($value))
            )
        )),
        'return'
    ));
}

function generateTryCatchCall($expr, $state) {
    return new PHPParser_Node_Stmt_TryCatch(
        array(new PHPParser_Node_Stmt_Return(
            new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Expr_Variable(CONT_NAME), array(
                $expr
            ))
        )),
        array(new PHPParser_Node_Stmt_Catch(
            new PHPParser_Node_Name('Exception'),
            VALUE_NAME,
            array(new PHPParser_Node_Stmt_Return(
                new PHPParser_Node_Expr_FuncCall(
                    new PHPParser_Node_Expr_ArrayDimFetch(
                        new PHPParser_Node_Expr_Variable(EXCEPT_NAME),
                        new PHPParser_Node_Scalar_LNumber($state->getCatchNum())
                    ),
                    array(new PHPParser_Node_Expr_Variable(VALUE_NAME))
                )
            ))
        ))
    );
}

function generateMethodCall($object, $function, $args, $type, $state) {
    $is_builtin_call = in_array($function, $GLOBALS['MAGIC_METHODS']);
    
    $get_class = new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('get_class'), array($object));
    if ($type == PHPParser_Node_Expr_StaticCall) {
        if ($object->toString() == 'self') {
            $object = $state->getSelf();
        }
        elseif ($object->toString() == 'parent') {
            $object = $state->getParent();
        }
        
        $get_class = new PHPParser_Node_Scalar_String($object->toString());
        
        if (isset($GLOBALS['CLASS_TO_BUILTIN_FUNCTIONS'][$object->toString()][$function])) {
            $is_builtin_call = true;
        }
    }
    

    if ($is_builtin_call) {
        $stmt = generateTryCatchCall(new $type($object, $function, $args), $state);
    }
    else {
        $orig_args = $args;
        array_unshift($args, $state->generateExceptParameter());
        array_unshift($args, new PHPParser_Node_Expr_Variable(CONT_NAME));
        
        $stmt = new PHPParser_Node_Stmt_If(
            new PHPParser_Node_Expr_Isset(array(
                new PHPParser_Node_Expr_ArrayDimFetch(
                    new PHPParser_Node_Expr_ArrayDimFetch(
                        new PHPParser_Node_Expr_ArrayDimFetch(
                            new PHPParser_Node_Expr_Variable('GLOBALS'),
                            new PHPParser_Node_Scalar_String('CLASS_TO_BUILTIN_METHODS')
                        ),
                        $get_class
                    ),
                    new PHPParser_Node_Scalar_String($function)
                )
            )),
            array(
                'stmts' => array(
                    generateTryCatchCall(new $type($object, $function, $orig_args), $state)
                ),
                'else' => new PHPParser_Node_Stmt_Else(array(
                    new PHPParser_Node_Stmt_Return(new $type($object, $function, $args))
                ))
            )
        );
    }
    
    return array(generateThunk(
        array(
            new PHPParser_Node_Expr_ClosureUse(CONT_NAME),
            new PHPParser_Node_Expr_ClosureUse(EXCEPT_NAME),
            new PHPParser_Node_Expr_ClosureUse(LOCALS_NAME, true),
            new PHPParser_Node_Expr_ClosureUse(TEMP_NAME, true)
        ),
        array($stmt),
        'call'
    ));
}

function wrapFunctionForCallback($function, $state) {
    $user_call_stmts = array(new PHPParser_Node_Stmt_Return(
        new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('call_user_func_array'), array(
            new PHPParser_Node_Expr_Variable(VALUE_NAME),
            new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('array_merge'), array(
                new PHPParser_Node_Expr_Array(array(
                    new PHPParser_Node_Expr_Variable(CONT_NAME),
                    new PHPParser_Node_Expr_Variable(EXCEPT_NAME)
                )),
                new PHPParser_Node_Expr_ArrayDimFetch(
                    new PHPParser_Node_Expr_Variable(TEMP_NAME),
                    new PHPParser_Node_Scalar_String(ARGS_TEMP_NAME)
                )
            ))
        ))
    ));
    $function_transformer = new PHPParser_Node_Expr_ArrayDimFetch(
        new PHPParser_Node_Expr_ArrayDimFetch(
            new PHPParser_Node_Expr_Variable('GLOBALS'),
            new PHPParser_Node_Scalar_String('BUILTIN_FUNCTIONS')
        ),
        new PHPParser_Node_Expr_Variable(VALUE_NAME)
    );
    $builtin_call_stmts = array(new PHPParser_Node_Stmt_Return(
        new PHPParser_Node_Expr_FuncCall($function_transformer, array(
            new PHPParser_Node_Expr_Variable(VALUE_NAME),
            new PHPParser_Node_Expr_ArrayDimFetch(
                new PHPParser_Node_Expr_Variable(TEMP_NAME),
                new PHPParser_Node_Scalar_String(ARGS_TEMP_NAME)
            ),
            new PHPParser_Node_Expr_Variable(CONT_NAME),
            $state->generateExceptParameter()
        ))
    ));
    
    // not a trampoline - this is the actual callback function that wraps the CPS callback - it takes parameters
    return new PHPParser_Node_Expr_Closure(array(
        'uses' => array(
            new PHPParser_Node_Expr_ClosureUse(LOCALS_NAME, true),
            new PHPParser_Node_Expr_ClosureUse(TEMP_NAME)
        ),
        'stmts' => array_merge(
            array(
                new PHPParser_Node_Expr_Assign(
                    new PHPParser_Node_Expr_ArrayDimFetch(
                        new PHPParser_Node_Expr_Variable(TEMP_NAME),
                        new PHPParser_Node_Scalar_String(ARGS_TEMP_NAME)
                    ),
                    new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('func_get_args'))
                )
            ),
            generateTrampoline(array(
                new PHPParser_Node_Expr_Assign(new PHPParser_Node_Expr_Variable(VALUE_NAME), $function),
                new PHPParser_Node_Stmt_If(
                    new PHPParser_Node_Expr_BooleanAnd(
                        new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('is_string'), array(new PHPParser_Node_Expr_Variable(VALUE_NAME))),
                        new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('array_key_exists'), array(
                            new PHPParser_Node_Expr_Variable(VALUE_NAME),
                            new PHPParser_Node_Expr_ArrayDimFetch(
                                new PHPParser_Node_Expr_Variable('GLOBALS'),
                                new PHPParser_Node_Scalar_String('BUILTIN_FUNCTIONS')
                            )
                        ))
                    ),
                    array(
                        'stmts' => $builtin_call_stmts,
                        'else' => new PHPParser_Node_Stmt_Else($user_call_stmts)
                    )
                )
            ))
        )
    ));
}

function generateFunctionCall($function, $args, $state) {
    if ($function instanceof PHPParser_Node_Scalar_String) {
        $function = new PHPParser_Node_Name($function->value);
    }
    
    $function_key = null;
    if ($function instanceof PHPParser_Node_Name && array_key_exists($function->toString(), $GLOBALS['STATIC_FUNCTION_TRANSFORM'])) {
        $function_key = $function->toString();
    }
    
    
    if ($function instanceof PHPParser_Node_Name) {
        if ($function_key) {
            return $GLOBALS['STATIC_FUNCTION_TRANSFORM'][$function_key]($function, $args, $state);
        }
        else {
            // if we have a null function key that means it's not a true builtin function
            // we need to check builtin list at runtime in case it is a "fast" function
            $callable_function = $function;
            $function = new PHPParser_Node_Scalar_String($function->toString());
            $assign_value = null;
        }
    }
    elseif ($function instanceof PHPParser_Node_Expr_Closure) {
        $stmts = $GLOBALS['STATIC_FUNCTION_TRANSFORM'][$function_key](new PHPParser_Node_Expr_Variable(VALUE_NAME), $args, $state);
        array_unshift($stmts, new PHPParser_Node_Expr_Assign(
            new PHPParser_Node_Expr_Variable(VALUE_NAME),
            $function
        ));
        return $stmts;
    }
    else {
        $assign_value = new PHPParser_Node_Expr_Assign(
            new PHPParser_Node_Expr_Variable(VALUE_NAME),
            $function
        );
        $function = new PHPParser_Node_Expr_Variable(VALUE_NAME);
        $callable_function = $function;
    }
    
    $user_call_stmts = $GLOBALS['STATIC_FUNCTION_TRANSFORM'][null]($callable_function, $args, $state);
    $function_transformer = new PHPParser_Node_Expr_ArrayDimFetch(
        new PHPParser_Node_Expr_ArrayDimFetch(
            new PHPParser_Node_Expr_Variable('GLOBALS'),
            new PHPParser_Node_Scalar_String('BUILTIN_FUNCTIONS')
        ),
        $function
    );
    $builtin_call_stmts = array(new PHPParser_Node_Stmt_Return(
        new PHPParser_Node_Expr_FuncCall($function_transformer, array(
            $function,
            new PHPParser_Node_Expr_Array(array_map(function ($arg) {
                return new PHPParser_Node_Expr_ArrayItem($arg->value, null, isLValue($arg->value));
            }, $args)),
            new PHPParser_Node_Expr_Variable(CONT_NAME),
            $state->generateExceptParameter()
        ))
    ));
    
    $conditions = new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('array_key_exists'),
        array(
            $function,
            new PHPParser_Node_Expr_ArrayDimFetch(
                new PHPParser_Node_Expr_Variable('GLOBALS'),
                new PHPParser_Node_Scalar_String('BUILTIN_FUNCTIONS')
            )
        )
    );
    if (!($function instanceof PHPParser_Node_Scalar_String)) {
        $conditions = new PHPParser_Node_Expr_BooleanAnd(
            new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('is_string'), array($function)),
            $conditions
        );
    }
    
    $stmts = array(new PHPParser_Node_Stmt_If($conditions, array(
        'stmts' => $builtin_call_stmts,
        'else' => new PHPParser_Node_Stmt_Else($user_call_stmts)
    )));
    
    if ($assign_value) {
        array_unshift($stmts, $assign_value);
    }
    
    return $stmts;
}

function generateJump($label_num) {
    return generateThunk(
        array(
            new PHPParser_Node_Expr_ClosureUse(LOCALS_NAME),
            new PHPParser_Node_Expr_ClosureUse(TEMP_NAME, true),
            new PHPParser_Node_Expr_ClosureUse(LABELS_NAME, true)
        ),
        array(new PHPParser_Node_Stmt_Return(
            new PHPParser_Node_Expr_FuncCall(
                new PHPParser_Node_Expr_ArrayDimFetch(
                    new PHPParser_Node_Expr_Variable(LABELS_NAME),
                    new PHPParser_Node_Scalar_LNumber($label_num)
                ),
                array(
                    new PHPParser_Node_Arg(new PHPParser_Node_Expr_Variable(LOCALS_NAME)),
                    new PHPParser_Node_Arg(new PHPParser_Node_Expr_Variable(TEMP_NAME))
                )
            )
        )),
        'jump'
    );
}

function generateParams($node_params, &$params, &$param_items) {
    $param_items = array();
    $params = array();
    $i = 0;
    foreach ($node_params as $param) {
        $param_name = PARAM_NAME . ($i++);
        
        $param_items[] = new PHPParser_Node_Expr_ArrayItem(
            new PHPParser_Node_Expr_Variable($param_name),
            new PHPParser_Node_Scalar_String($param->name),
            true
        );
        
        $params[] = new PHPParser_Node_Param($param_name, $param->default, $param->type, $param->byRef);
    }
}

class FunctionState {
    private $is_return_by_ref = false;
    private $block_num = 0;
    private $basic_blocks = array();
    private $basic_block_aliases = array();
    private $break_stack = array();
    private $continue_stack = array();
    private $label_names = array();
    private $catch_num = 0;
    private $catches = array();
    private $self = null;
    private $parent = null;
    private $builtin_methods = array();
    private $is_in_instance_method = false;

    function setIsInInstanceMethod($is_in_instance_method) {
        $this->is_in_instance_method = $is_in_instance_method;
    }
    
    function isInInstanceMethod() {
        return $this->is_in_instance_method;
    }

    function addBuiltinMethod($name) {
        $this->builtin_methods[$name] = true;
    }

    function generateBuiltinMethodDeclarations() {
        return array(new PHPParser_Node_Expr_Assign(
            new PHPParser_Node_Expr_ArrayDimFetch(
                new PHPParser_Node_Expr_ArrayDimFetch(
                    new PHPParser_Node_Expr_Variable('GLOBALS'),
                    new PHPParser_Node_Scalar_String('CLASS_TO_BUILTIN_METHODS')
                ),
                new PHPParser_Node_Scalar_String($this->getSelf())
            ),
            new PHPParser_Node_Expr_Array(array_map(
                function($method_name) {
                    return new PHPParser_Node_Expr_ArrayItem(
                        new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name('true')),
                        new PHPParser_Node_Scalar_String($method_name)
                    );
                },
                array_keys($this->builtin_methods)
            ))
        ));
    }

    function setSelf($self) {
        $this->self = $self;
    }

    function getSelf() {
        return $this->self;
    }

    function setParent($parent) {
        $this->parent = $parent;
    }

    function getParent() {
        return $this->parent;
    }

    function setIsReturnByRef($is_return_by_ref = true) {
        $this->is_return_by_ref = $is_return_by_ref;
    }
    
    function getIsReturnByRef() {
        return $this->is_return_by_ref;
    }

    function addBreakAndContinue($break_num, $continue_num) {
        $state = clone $this;
        $state->block_num =& $this->block_num;
        $state->basic_blocks =& $this->basic_blocks;
        $state->basic_block_aliases =& $this->basic_block_aliases;
        $state->label_names =& $this->label_names;
        $state->catch_num =& $this->catch_num;
        $state->catches =& $this->catches;
        array_unshift($state->break_stack, $break_num);
        array_unshift($state->continue_stack, $continue_num);
        return $state;
    }
    
    function getCatchNum() {
        return $this->catch_num;
    }
    
    function generateExceptParameter() {
        return new PHPParser_Node_Expr_Array(array(new PHPParser_Node_Expr_ArrayItem(
            new PHPParser_Node_Expr_ArrayDimFetch(
                new PHPParser_Node_Expr_Variable(EXCEPT_NAME),
                new PHPParser_Node_Scalar_LNumber($this->catch_num)
            )
        )));
    }
    
    function addCatches($catches) {
        $state = clone $this;
        $state->block_num =& $this->block_num;
        $state->basic_blocks =& $this->basic_blocks;
        $state->basic_block_aliases =& $this->basic_block_aliases;
        $state->label_names =& $this->label_names;
        $state->break_stack =& $this->break_stack;
        $state->continue_stack =& $this->continue_stack;
        $state->catches =& $this->catches;
        $state->catch_num = $this->catch_num + 1;
        $state->catches[$state->catch_num] = $catches;
        return $state;
    }
    
    function generateThrow($exception) {
        $stmts = array();
        $exception = assignToTemp($exception, $stmts);
        // trampoline
        $stmts[] = generateThunk(
            array(
                new PHPParser_Node_Expr_ClosureUse(EXCEPT_NAME),
                new PHPParser_Node_Expr_ClosureUse(TEMP_NAME)
            ),
            array(
                new PHPParser_Node_Stmt_Return(
                    new PHPParser_Node_Expr_FuncCall(
                        new PHPParser_Node_Expr_ArrayDimFetch(
                            new PHPParser_Node_Expr_Variable(EXCEPT_NAME),
                            new PHPParser_Node_Scalar_LNumber($this->catch_num)
                        ),
                        array($exception)
                    )
                )
            ),
            'throw'
        );
        return $stmts;
    }
    
    function generateCatches() {
        $stmts = array();
        foreach ($this->catches as $catch_num => $catches) {
            $stmts[] = new PHPParser_Node_Expr_Assign(
                new PHPParser_Node_Expr_ArrayDimFetch(new PHPParser_Node_Expr_Variable(EXCEPT_NAME)),
                new PHPParser_Node_Expr_Closure(array(
                    'params' => array(new PHPParser_Node_Param(VALUE_NAME)),
                    'uses' => array(
                        new PHPParser_Node_Expr_ClosureUse(CONT_NAME),
                        new PHPParser_Node_Expr_ClosureUse(EXCEPT_NAME, true),
                        new PHPParser_Node_Expr_ClosureUse(LOCALS_NAME, true),
                        new PHPParser_Node_Expr_ClosureUse(TEMP_NAME, true),
                        new PHPParser_Node_Expr_ClosureUse(LABELS_NAME, true)
                    ),
                    'stmts' => $catches
                ))
            );
        }
        return $stmts;
    }
    
    function generateBlockNum() {
        return $this->block_num++;
    }
    
    function addBasicBlock($block_num, $stmts) {
        if (count($stmts) == 1 &&
            ($return = $stmts[0]) instanceof PHPParser_Node_Stmt_Return &&
            ($closure = $return->expr) instanceof PHPParser_Node_Expr_Closure &&
            count($closure->stmts) == 1 &&
            ($return = $closure->stmts[0]) instanceof PHPParser_Node_Stmt_Return &&
            ($funccall = $return->expr) instanceof PHPParser_Node_Expr_FuncCall &&
            ($name = $funccall->name) instanceof PHPParser_Node_Expr_ArrayDimFetch &&
            ($var = $name->var) instanceof PHPParser_Node_Expr_Variable &&
            $var->name == LABELS_NAME)
        {
            // If all a basic block does is goto another basic block, point the basic
            // block pointer directly at the other block.
            $this->basic_block_aliases[$block_num] = $name->dim->value;
        }
        else {       
            $this->basic_blocks[$block_num] = $stmts;
        }
    }
    
    function generateBasicBlocks() {
        $assignments = array();
        foreach ($this->basic_blocks as $num => $block) {
            $assignments[] = new PHPParser_Node_Expr_Assign(
                new PHPParser_Node_Expr_ArrayDimFetch(
                    new PHPParser_Node_Expr_Variable(LABELS_NAME),
                    new PHPParser_Node_Scalar_LNumber($num)
                ),
                new PHPParser_Node_Expr_Closure(array(
                    'params' => array(
                        new PHPParser_Node_Param(LOCALS_NAME, null, null, true),
                        new PHPParser_Node_Param(TEMP_NAME, null, null, true)
                    ),
                    'uses' => array(
                        new PHPParser_Node_Expr_ClosureUse(CONT_NAME),
                        new PHPParser_Node_Expr_ClosureUse(EXCEPT_NAME, true),
                        new PHPParser_Node_Expr_ClosureUse(LABELS_NAME, true)
                    ),
                    'stmts' => $block
                ))
            );
        }
        foreach ($this->basic_block_aliases as $num => $to) {
            while (array_key_exists($to, $this->basic_block_aliases)) {
                $to = $this->basic_block_aliases[$to]; // Fully resolve aliases
            }
            $assignments[] = new PHPParser_Node_Expr_Assign(
                new PHPParser_Node_Expr_ArrayDimFetch(
                    new PHPParser_Node_Expr_Variable(LABELS_NAME),
                    new PHPParser_Node_Scalar_LNumber($num)
                ),
                new PHPParser_Node_Expr_ArrayDimFetch(
                    new PHPParser_Node_Expr_Variable(LABELS_NAME),
                    new PHPParser_Node_Scalar_LNumber($to)
                )
            );
        }
        return $assignments;
    }
    
    function addLabel($num, $name) {
        $this->label_names[$name] = $num;
    }
    
    function getLabel($label) {
        if (!array_key_exists($label, $this->label_names)) {
            throw new Exception('No such label: ' . $label);
        }
        return generateJump($this->label_names[$label]);
    }
    
    function getBreak($n = 1) {
        if (!$n) {
            $n = 1;
        }
        if ($n > count($this->break_stack)) {
            throw new Exception('Too many break levels');
        }
        return generateJump($this->break_stack[$n - 1]);
    }
    
    function getContinue($n = 1) {
        if (!$n) {
            $n = 1;
        }
        if ($n > count($this->continue_stack)) {
            throw new Exception('Too many continue levels');
        }
        return generateJump($this->continue_stack[$n - 1]);
    }
}

function traverseStatements($stmts, $final, $after_stmts, $state, $is_top_level = false) {
    if ($stmts === null) { // This is for functions in interfaces, but probably need to do it differently TODO
        return $final(null, $state);
    }

    // Hoist functions (and classes, even though PHP doesn't exactly do so)
    $functions = array();
    $statements = array();
    foreach ($stmts as $stmt) {
        if ($stmt instanceof PHPParser_Node_Stmt_Function/* ||
            $stmt instanceof PHPParser_Node_Stmt_Class ||
            $stmt instanceof PHPParser_Node_Stmt_Interface*/)
        {
            $functions[] = $stmt;
        }
        else {
            $statements[] = $stmt;
        }
    }
    $stmts = array_merge($functions, $statements);

    return call_user_func($loop = function ($final, $stmts, $state) use (&$loop, $after_stmts) {
        if ($node = array_shift($stmts)) {
            return function () use ($node, $final, &$loop, $stmts, $state) {
                return traverseNode($node, function ($node_result, $final, $state) use (&$loop, $stmts) {
                    return $loop(function ($future_result, $state) use ($node_result, $final, $stmts) {
                        if (!isset($node_result)) {
                            // do nothing
                        }
                        elseif (is_array($node_result)) {
                            $future_result = array_merge($node_result, $future_result);
                        }
                        else {
                            array_unshift($future_result, $node_result);
                        }
                        return $final($future_result, $state);
                    }, $stmts, $state);
                }, $final, $state);
            };
        }
        else {
            return $final($after_stmts, $state);
        }
    }, function ($result, $state) use ($final, $is_top_level) {
        if ($is_top_level) {
            $result = array_merge($state->generateBasicBlocks(), $state->generateCatches(), $result);
        }
        return $final($result, $state);
    }, $stmts, $state);
}

// $next is a function($node_result, $final, $state), where $node_result is a node or array of nodes and $final is the new final step
// $final is a function($result), where $result is an array of statements
function traverseNode($node, $next, $final, $state) {
    if ($node instanceof PHPParser_Node_Scalar_LineConst) {
        return $next(new PHPParser_Node_Scalar_LNumber($node->line), $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Scalar_FileConst) {
        return $next(new PHPParser_Node_Scalar_String($GLOBALS['file']), $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Scalar_DirConst) {
        return $next(new PHPParser_Node_Scalar_String(dirname($GLOBALS['file'])), $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Scalar_Encapsed) {
        return call_user_func($loop = function ($parts, $compiled_parts, $final, $state) use (&$loop, $next) {
            if (count($parts)) {
                $part = array_shift($parts);
                return traverseNode($part, function ($part, $final, $state) use (&$loop, $parts, $compiled_parts) {
                    $compiled_parts[] = $part;
                    return $loop($parts, $compiled_parts, $final, $state);
                }, $final, $state);
            }
            else {
                return $next(new PHPParser_Node_Scalar_Encapsed($compiled_parts), $final, $state);
            }
        }, $node->parts, array(), $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_ConstFetch && $node->name->toString() == '__COMPILER_HALT_OFFSET__') {
        if (isset($GLOBALS['compiler_halt_offset'])) {
            $result = $GLOBALS['compiler_halt_offset'];
        }
        else {
            $result = new NodeReference($node);
            $GLOBALS['compiler_halt_offset_nodes'][] = $result;
        }
        return $next($result, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_HaltCompiler) {
        $offset = new PHPParser_Node_Scalar_LNumber(strlen($GLOBALS['source']) - strlen($node->remaining));
        if (isset($GLOBALS['compiler_halt_offset_nodes'])) {
            foreach ($GLOBALS['compiler_halt_offset_nodes'] as $offset_node) {
                $offset_node->setNode($offset);
            }
            unset($GLOBALS['compiler_halt_offset_nodes']);
        }
        $GLOBALS['compiler_halt_offset'] = $offset;
        return $final(generateReturn(new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name('null')), $state), $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Const) {
        return call_user_func($loop = function ($consts, $compiled_consts, $final, $state) use (&$loop, $next) {
            if ($const = array_shift($consts)) {
                return traverseNode($const->value, function ($const_value, $final, $state) use ($const, $consts, &$loop) {
                    $compiled_consts[] = new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('define'), array(
                        new PHPParser_Node_Scalar_String($const->name),
                        $const_value
                    ));
                    return function () use (&$loop, $consts, $compiled_consts, $final, $state) {
                        return $loop($consts, $compiled_consts, $final, $state);
                    };
                }, $final, $state);
            }
            else {
                return $next($compiled_consts, $final, $state);
            }
        }, $node->consts, array(), $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_ConstFetch) {
        return $next($node, $final, $state);
    }
    elseif ($node === null || is_string($node) || $node instanceof PHPParser_Node_Scalar || $node instanceof PHPParser_Node_Name || $node instanceof PHPParser_Node_Stmt_InlineHTML) {
        return $next($node, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_While) {
        $continue_num = $state->generateBlockNum();
        $break_num = $state->generateBlockNum();
        
        return traverseNode($node->cond, function ($cond, $final, $state) use ($node, $next, $continue_num, $break_num) {
            return traverseStatements($node->stmts, function ($while_body) use ($cond, $next, $continue_num, $break_num, $final, $state) {
                return $next(null, function ($after_while, $state) use ($break_num, $continue_num, $while_body, $cond, $final) {
                    $state->addBasicBlock($break_num, $after_while);
                    
                    $while_body = array(
                        new PHPParser_Node_Stmt_If($cond, array('stmts' => $while_body)),
                        generateJump($break_num)
                    );
                    
                    return $final($while_body, $state);
                }, $state);
            }, array(generateJump($continue_num)), $state->addBreakAndContinue($break_num, $continue_num));
        }, function ($while_body, $state) use ($final, $continue_num) {
            $state->addBasicBlock($continue_num, $while_body);
            return $final(array(generateJump($continue_num)), $state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Do) {
        $continue_num = $state->generateBlockNum();
        $break_num = $state->generateBlockNum();
        $body_num = $state->generateBlockNum();
        
        return traverseNode($node->cond, function ($cond, $final, $state) use ($node, $next, $continue_num, $break_num, $body_num) {
            return traverseStatements($node->stmts, function ($do_body) use ($cond, $next, $continue_num, $break_num, $body_num, $final, $state) {
                $state->addBasicBlock($body_num, $do_body);
            
                return $next(null, function ($after_do, $state) use ($break_num, $continue_num, $body_num, $do_body, $cond, $final) {
                    $state->addBasicBlock($break_num, $after_do);
                    
                    return $final(array(
                        new PHPParser_Node_Stmt_If($cond,
                            array('stmts' => array(generateJump($body_num)))
                        ),
                        generateJump($break_num)
                    ), $state);
                }, $state);
            }, array(generateJump($continue_num)), $state->addBreakAndContinue($break_num, $continue_num));
        }, function ($cond_block, $state) use ($final, $continue_num, $body_num) {
            $state->addBasicBlock($continue_num, $cond_block);
            return $final(array(generateJump($body_num)), $state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_For) {
        $continue_num = $state->generateBlockNum();
        $break_num = $state->generateBlockNum();
        
        return traverseStatements($node->init, function ($init) use ($node, $continue_num, $break_num, $state, $next, $final) {
            $cond = $node->cond;
            $cond_var = generateTemp();
            if (count($cond)) {
                $cond[] = new PHPParser_Node_Expr_Assign($cond_var, array_pop($cond));
            }
            
            return call_user_func($cond_loop = function ($conds, $compiled_conds, $state) use (&$cond_loop, $node, $next, $final, $continue_num, $break_num, $init) {
                if ($cond = array_shift($conds)) {
                    return traverseNode($cond, function ($cond, $final, $state) use (&$cond_loop, $conds, $compiled_conds) {
                        $compiled_conds[] = $cond;
                        return function () use (&$cond_loop, $conds, $compiled_conds, $state) {
                            return $cond_loop($conds, $compiled_conds, $state);
                        };
                    }, $final, $state);
                }
                else {
                    return traverseStatements($node->loop, function ($loop) use ($node, $state, $break_num, $continue_num, $compiled_conds, $next, $final, $init) {
                        return traverseStatements($node->stmts, function ($for_body) use ($state, $continue_num, $break_num, $compiled_conds, $next, $final, $init) {
                            if (count($compiled_conds)) {
                                $last_cond = array_pop($compiled_conds);
                                $for_body = array_merge($compiled_conds, array(
                                    new PHPParser_Node_Stmt_If($last_cond, array(
                                        'stmts' => $for_body
                                    ))
                                ));
                            }
                            $for_body[] = generateJump($break_num);
                            $state->addBasicBlock($continue_num, $for_body);
                            
                            return $next(null, function ($after_for, $state) use ($break_num, $final, $init) {
                                $state->addBasicBlock($break_num, $after_for);
                                
                                return $final($init, $state);
                            }, $state);
                        }, $loop, $state->addBreakAndContinue($break_num, $continue_num));
                    }, array(generateJump($continue_num)), $state);
                }
            }, $node->cond, array(), $state);
        }, array(generateJump($continue_num)), $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Foreach) {
        // TODO handle non-array Traversables
        $continue_num = $state->generateBlockNum();
        $break_num = $state->generateBlockNum();
        return traverseNode($node->expr, function ($expr, $final, $state) use ($continue_num, $break_num, $node, $next) {
            $stmts = array();
            $temp = assignToTemp($expr, $stmts, $node->byRef);
            $stmts[] = new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('reset'), array($temp));
            
            return traverseStatements($node->stmts, function ($foreach_body) use ($final, $state, $node, $next, $continue_num, $break_num, $stmts, $temp) {
                return traverseNode($node->keyVar, function ($keyVar, $final, $state) use ($foreach_body, $node, $next, $break_num, $temp) {
                    return traverseNode($node->valueVar, function ($valueVar, $final, $state) use ($foreach_body, $keyVar, $next, $break_num, $temp) {
                        return $next(null, function ($after_foreach, $state) use ($break_num, $foreach_body, $temp, $final, $keyVar, $valueVar) {
                            $state->addBasicBlock($break_num, $after_foreach);
                            
                            $keyTemp = generateTemp();
                            
                            array_unshift($foreach_body, new PHPParser_Node_Expr_AssignRef(
                                $valueVar,
                                new PHPParser_Node_Expr_ArrayDimFetch($temp, $keyTemp)
                            ));
                            if ($keyVar) {
                                array_unshift($foreach_body, new PHPParser_Node_Expr_Assign(
                                    $keyVar,
                                    $keyTemp
                                ));
                            }
                            
                            $foreach_body = array(
                                new PHPParser_Node_Stmt_If(
                                    new PHPParser_Node_Expr_AssignList(
                                        array($keyTemp, null),
                                        new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('each'), array($temp))
                                    ),
                                    array('stmts' => $foreach_body)
                                ),
                                generateJump($break_num)
                            );
                            
                            return $final($foreach_body, $state);
                        }, $state);
                    }, $final, $state);
                }, function ($foreach_body, $state) use ($final, $continue_num, $stmts) {
                    $state->addBasicBlock($continue_num, $foreach_body);
                    $stmts[] = generateJump($continue_num);
                    return $final($stmts, $state);
                }, $state);
            }, array(generateJump($continue_num)), $state->addBreakAndContinue($break_num, $continue_num));
        }, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_If) {
        $done_num = $state->generateBlockNum();
        
        return traverseNode($node->cond, function ($cond, $final, $state) use ($node, $next, $done_num) {
            $next_num = $done_num;
            if (count($node->elseifs) > 0 || isset($node->else)) {
                $next_num = $state->generateBlockNum();
            }
            return traverseStatements($node->stmts, function ($if_body, $state) use ($cond, $next_num, $done_num, $final, $node) {
                $if_body = array(
                    new PHPParser_Node_Stmt_If($cond, array('stmts' => $if_body)),
                    generateJump($next_num)
                );
                
                // Handle zero or more elseif blocks
                $elseifs = $node->elseifs;
                $else = $node->else;
                return call_user_func($loop = function ($next_num, $elseifs, $final, $state) use (&$loop, $done_num, $else, $if_body) {
                    if (isset($elseifs) && $elseif = array_shift($elseifs)) {
                        $elseif_num = $next_num;
                        $next_num = $done_num;
                        if (count($elseifs) > 0 || isset($else)) {
                            $next_num = $state->generateBlockNum();
                        }
                        return traverseNode($elseif->cond, function ($cond, $final, $state) use (&$loop, $elseif, $elseif_num, $next_num, $done_num, $elseifs) {
                            return traverseStatements($elseif->stmts, function ($elseif_body, $state) use (&$loop, $elseif_num, $next_num, $cond, $elseifs, $final) {
                                $elseif_body = array(
                                    new PHPParser_Node_Stmt_If($cond, array('stmts' => $elseif_body)),
                                    generateJump($next_num)
                                );
                                $state->addBasicBlock($elseif_num, $elseif_body);
                                return function () use (&$loop, $next_num, $elseifs, $final, $state) {
                                    return $loop($next_num, $elseifs, $final, $state);
                                };
                            }, array(generateJump($done_num)), $state);
                        }, $final, $state);
                    }
                    elseif (isset($else)) {
                        return traverseStatements($else->stmts, function ($else_body, $state) use ($next_num, $if_body, $final) {
                            $state->addBasicBlock($next_num, $else_body);
                            return $final($if_body, $state);
                        }, array(generateJump($done_num)), $state);
                    }
                    else {
                        return $final($if_body, $state);
                    }
                }, $next_num, $elseifs, $final, $state);
            }, array(generateJump($done_num)), $state);
        }, function ($if_body, $state) use ($final, $done_num, $next) {
            return $next(null, function ($after_if, $state) use ($done_num, $final, $if_body) {
                $state->addBasicBlock($done_num, $after_if);
                return $final($if_body, $state);
            }, $state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Break) {
        $num = $node->num->value;
        return $next(null, function ($result, $new_state) use ($final, $state, $num) {
            return $final(array($state->getBreak($num)), $new_state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Continue) {
        $num = $node->num->value;
        return $next(null, function ($result, $new_state) use ($final, $state, $num) {
            return $final(array($state->getContinue($num)), $new_state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Label) {
        $label_num = $state->generateBlockNum();
        $state->addLabel($label_num, $node->name);
        return $next(null, function ($after_label, $state) use ($label_num, $final) {
            $state->addBasicBlock($label_num, $after_label);
            return $final(array(generateJump($label_num)), $state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Goto) {
        $name = $node->name;
        return $next(null, function ($after_goto, $state) use ($final, $name) {
            return $final(array($state->getLabel($name)), $state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_Exit) {
        return traverseNode($node->expr, function ($expr, $final, $state) use ($next) {
            return $next(null, function ($stmts_x, $state_x) use ($expr, $state, $final) {
                // Executable statements after exit() are not reached, but we run the compiler over them anyway.
                return $final(array(new PHPParser_node_Expr_Exit($expr)), $state);
            }, $state);
        }, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Return) {
        return traverseNode($node->expr, new ReturnClosure(function ($expr, $final, $state) use ($next) {
            return $next(null, function ($stmts_x, $state_x) use ($expr, $state, $final) {
                // Executable statements after a return are not reached, but we run the compiler over them in case doing so has side effects internal to the compiler (like __halt_compiler())
                return $final(generateReturn($expr, $state), $state);
            }, $state);
        }), $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_FuncCall) {
        return generateContinuation($next, function ($continuation, $state) use ($node, $final) {
            return traverseNode($node->name, function ($function, $final, $state) use ($next, $node) {
                return call_user_func($loop = function ($final, $i, $compiled_args, $state) use (&$loop, $function, $node, $next) {
                    if ($i < count($node->args)) {
                        $arg = $node->args[$i++];
                        $byRef = $arg->byRef;
                        return function () use (&$loop, $arg, $i, $byRef, $compiled_args, $final, $state) {
                            return traverseNode($arg->value, function ($node_result, $final, $state) use (&$loop, $i, $byRef, $compiled_args) {
                                $compiled_args[] = new PHPParser_Node_Arg($node_result, $byRef);
                                return $loop($final, $i, $compiled_args, $state);
                            }, $final, $state);
                        };
                    }
                    else {
                        // trampoline
                        return $final(array(generateThunk(
                            array(
                                new PHPParser_Node_Expr_ClosureUse(CONT_NAME),
                                new PHPParser_Node_Expr_ClosureUse(EXCEPT_NAME),
                                new PHPParser_Node_Expr_ClosureUse(LOCALS_NAME, true),
                                new PHPParser_Node_Expr_ClosureUse(TEMP_NAME)
                            ),
                            generateFunctionCall($function, $compiled_args, $state),
                            'call'
                        )), $state);
                    }
                }, $final, 0, array(), $state);
            }, generateFinalForContinuation($final, $continuation), $state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Function) {
        $name = $node->name;
        generateParams($node->params, $params, $param_items);
        array_unshift($params, new PHPParser_Node_Param(EXCEPT_NAME));
        array_unshift($params, new PHPParser_Node_Param(CONT_NAME));
        
        $new_state = new FunctionState();
        $new_state->setIsReturnByRef($node->byRef);
        
        // Provide a way to escape from CPS if a function doesn't need it.
        if (count($node->stmts) &&
            $node->stmts[0] instanceof PHPParser_Node_Scalar_String &&
            $node->stmts[0]->value == 'fast')
        {
            // "fast" pragma to treat this function as a builtin
            // this promises that the function does not call any non-fast functions
            __cps_add_builtin($node->name);
            array_shift($node->stmts); // drop the pragma
            return $next(array(
                $node,
                new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('__cps_add_builtin'), array(
                    new PHPParser_Node_Scalar_String($node->name)
                ))
            ), $final, $state);
        }
        
        return traverseStatements($node->stmts, function ($result, $new_state) use ($next, $final, $name, $params, $param_items, $byRef, $state) {
            $result = array_merge(
                array(
                    new PHPParser_Node_Expr_Assign(
                        new PHPParser_Node_Expr_Variable(LOCALS_NAME),
                        new PHPParser_Node_Expr_Array($param_items)
                    ),
                    new PHPParser_Node_Expr_Assign(
                        new PHPParser_Node_Expr_Variable(TEMP_NAME),
                        generateDefaultTemps()
                    ),
                    new PHPParser_Node_Expr_Assign(
                        new PHPParser_Node_Expr_Variable(LABELS_NAME),
                        new PHPParser_Node_Expr_Array()
                    )
                ),
                $result);
            $result = new PHPParser_Node_Stmt_Function($name, array(
                'params' => $params,
                'stmts' => $result
            ));
            
            return $next($result, $final, $state);
        }, generateReturn(new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name('null')), $new_state), $new_state, true);
    }
    elseif ($node instanceof PHPParser_Node_Expr_Closure) {
        generateParams($node->params, $params, $param_items);
        array_unshift($params, new PHPParser_Node_Param(EXCEPT_NAME));
        array_unshift($params, new PHPParser_Node_Param(CONT_NAME));
        
        if ($state->isInInstanceMethod()) {
            $param_items[] = new PHPParser_Node_Expr_ArrayItem(
                new PHPParser_Node_Expr_Variable('this'),
                new PHPParser_Node_Scalar_String('this'),
                true
            );
        }
        
        $uses = array();
        foreach ($node->uses as $use) {
            $name = new PHPParser_Node_Scalar_String($use->var);
            $uses[] = new PHPParser_Node_Expr_ArrayItem(
                new PHPParser_Node_Expr_ArrayDimFetch(new PHPParser_Node_Expr_Variable(LOCALS_NAME), $name),
                $name,
                $use->byRef
            );
        }
        $uses = new PHPParser_Node_Expr_Assign(
            new PHPParser_Node_Expr_Variable(USES_NAME),
            new PHPParser_Node_Expr_Array($uses)
        );
        
        $new_state = new FunctionState();
        $new_state->setSelf($state->getSelf());
        $new_state->setParent($state->getParent());
        $new_state->setIsReturnByRef($node->byRef);
        $new_state->setIsInInstanceMethod($state->isInInstanceMethod());
        return traverseStatements($node->stmts, function ($result, $new_state) use ($param_items, $next, $final, $uses, $params, $state) {
            $result = array_merge(
                array(
                    new PHPParser_Node_Expr_Assign(
                        new PHPParser_Node_Expr_Variable(LOCALS_NAME),
                        new PHPParser_Node_Expr_Plus(
                            new PHPParser_Node_Expr_Variable(USES_NAME),
                            new PHPParser_Node_Expr_Array($param_items)
                        )
                    ),
                    new PHPParser_Node_Expr_Assign(
                        new PHPParser_Node_Expr_Variable(TEMP_NAME),
                        generateDefaultTemps()
                    ),
                    new PHPParser_Node_Expr_Assign(
                        new PHPParser_Node_Expr_Variable(LABELS_NAME),
                        new PHPParser_Node_Expr_Array()
                    )
                ),
                $result);
            $stmts = array($uses);
            $temp = assignToTemp(new PHPParser_Node_Expr_Closure(array(
                'params' => $params,
                'uses' => array(new PHPParser_Node_Expr_ClosureUse(USES_NAME)),
                'stmts' => $result
            )), $stmts);
            return $next($temp, function ($result, $state) use ($final, $stmts) {
                return $final(array_merge($stmts, $result), $state);
            }, $state);
        }, generateReturn(new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name('null')), $new_state/*TODO ??? */), $new_state, true);
    }
    elseif ($node instanceof PHPParser_Node_Expr_Array) {
        $node_items = $node->items;
        return call_user_func($loop = function ($final, $items, $i, $state) use (&$loop, $node_items, $next) {
            if ($i < count($node_items)) {
                $item = $node_items[$i++];
                
                $value = $item->value;
                $byRef = $item->byRef;
                return traverseNode($item->key, function ($key, $final, $state) use ($value, $byRef, &$loop, $i, $items) {
                    return traverseNode($value, function ($value, $final, $state) use ($byRef, &$loop, $i, $key, $items) {
                        $items[] = new PHPParser_Node_Expr_ArrayItem($value, $key, $byRef);
                        return function () use (&$loop, $final, $items, $i, $state) {
                            return $loop($final, $items, $i, $state);
                        };
                    }, $final, $state);
                }, $final, $state);
            }
            else {
                return $next(new PHPParser_Node_Expr_Array($items), $final, $state);
            }
        }, $final, array(), 0, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Unset) {
        return call_user_func($loop = function ($vars, $compiled_vars, $final, $state) use (&$loop, $next) {
            if ($var = array_shift($vars)) {
                return traverseNode($var, function ($var, $final, $state) use (&$loop, $compiled_vars, $vars) {
                    $compiled_vars[] = $var;
                    return $loop($vars, $compiled_vars, $final, $state);
                }, $final, $state);
            }
            else {
                return $next(new PHPParser_Node_Stmt_Unset($compiled_vars), $final, $state);
            }
        }, $node->vars, array(), $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_Isset) {
        // isset() short circuits once it finds an unset variable, evaluating left to right
        $vars = $node->vars;
        return generateContinuation($next, function ($continuation, $state) use ($vars, $final) {
            return call_user_func($loop = function ($i, $final, $state) use (&$loop, $vars, $continuation) {
                $var = $vars[$i++];
                if ($i < count($vars)) {
                    $next = function ($junk, $final, $state) use (&$loop, $i) {
                        return $loop($i, $final, $state);
                    };
                    return $next(null, function ($continuation_stmts, $state) use (&$loop, $final, $next, $i, $vars, $var) {
                        return traverseNode($var, function ($var, $final, $state) use (&$loop, $i, $continuation_stmts) {
                            return $final(array(
                                new PHPParser_Node_Stmt_If(
                                    new PHPParser_Node_Expr_Isset(array($var)),
                                    array(
                                        'stmts' => $continuation_stmts,
                                        'else' => new PHPParser_Node_Stmt_Else(
                                            generateReturn(new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name('false')), $state)
                                        )
                                    )
                                )
                            ), $state);
                        }, $final, $state);
                    }, $state);
                }
                else {
                    return traverseNode($var, function ($var, $final, $state) {
                        return $final(generateReturn(new PHPParser_Node_Expr_Isset(array($var)), $state), $state);
                    }, $final, $state);
                }
            }, 0, generateFinalForContinuation($final, $continuation), $state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_PostDec || // unary that takes a "var"
        $node instanceof PHPParser_Node_Expr_PostInc ||
        $node instanceof PHPParser_Node_Expr_PreDec ||
        $node instanceof PHPParser_Node_Expr_PreInc)
    {
        $node_class = get_class($node);
        return traverseNode($node->var, function ($result, $final, $state) use ($node_class, $next) {
            return $next(new $node_class($result), $final, $state);
        }, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_BitwiseNot || // unary that takes a "expr"
        $node instanceof PHPParser_Node_Expr_BooleanNot ||
        $node instanceof PHPParser_Node_Expr_ErrorSuppress ||
        $node instanceof PHPParser_Node_Expr_Print ||
        $node instanceof PHPParser_Node_Expr_UnaryMinus ||
        $node instanceof PHPParser_Node_Expr_UnaryPlus ||
        $node instanceof PHPParser_Node_Expr_Cast ||
        $node instanceof PHPParser_Node_Expr_Clone)
    {
        $node_class = get_class($node);
        return traverseNode($node->expr, function ($result, $final, $state) use ($node_class, $next) {
            return $next(new $node_class($result), $final, $state);
        }, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Echo) {
        $exprs = $node->exprs;
        return call_user_func($loop = function ($i, $results, $final, $state) use (&$loop, $exprs, $next) {
            if ($i < count($exprs)) {
                $expr = $exprs[$i++];
                return function () use ($expr, &$loop, $i, $final, $results, $state) {
                    return traverseNode($expr, function ($result, $final, $state) use (&$loop, $i, $results) {
                        $results[] = $result;
                        return $loop($i, $results, $final, $state);
                    }, $final, $state); 
                };
            }
            else {
                return $next(new PHPParser_Node_Stmt_Echo($results), $final, $state);
            }
        }, 0, array(), $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Global) {
        $vars = $node->vars;
        return call_user_func($loop = function ($i, $results, $final, $state) use (&$loop, $vars, $next) {
            if ($i < count($vars)) {
                $var = $vars[$i++];
                if ($var instanceof PHPParser_Node_Expr_Variable) {
                    $name = $var->name;
                    return function () use ($name, &$loop, $i, $final, $results, $state) {
                        return traverseNode($name, function ($name, $final, $state) use (&$loop, $i, $results) {
                            if (is_string($name)) {
                                $name = new PHPParser_Node_Scalar_String($name);
                            }
                            else {
                                $name = assignToTemp($name, $results);
                            }
                            $results[] = new PHPParser_Node_Expr_AssignRef(
                                new PHPParser_Node_Expr_ArrayDimFetch(
                                    new PHPParser_Node_Expr_Variable(LOCALS_NAME),
                                    $name
                                ),
                                new PHPParser_Node_Expr_ArrayDimFetch(
                                    new PHPParser_Node_Expr_ArrayDimFetch(
                                        new PHPParser_Node_Expr_Variable(TEMP_NAME),
                                        new PHPParser_Node_Scalar_String(GLOBALS_TEMP_NAME)
                                    ),
                                    $name
                                )
                            );
                            return $loop($i, $results, $final, $state);
                        }, $final, $state);
                    };
                }
                else {
                    throw new Exception('Cannot globalize a non-variable');
                }
            }
            else {
                return $next($results, $final, $state);
            }
        }, 0, array(), $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_Ternary) {
        return generateContinuation($next, function ($continuation, $state) use ($node, $next, $final) {
            $if = $node->if;
            $else = $node->else;
            return traverseNode($node->cond, function ($cond, $final, $state) use ($next, $if, $else) {
                $ifTemp = null;
                if (!isset($if)) {
                    $ifTemp = generateTemp();
                    $cond = new PHPParser_Node_Expr_Assign($ifTemp, $cond);
                }
                
                $ifFinal = function ($ifBranch, $state) use ($final, $else, $next, $cond) {
                    $elseNext = function ($else, $final, $state) {
                        return $final(generateReturn($else, $state), $state);
                    };
                    if ($next instanceof ReturnClosure) {
                        $elseNext = new ReturnClosure($elseNext);
                    }
                    
                    return traverseNode($else, $elseNext, function ($elseBranch, $state) use ($final, $ifBranch, $cond) {
                        return $final(array(new PHPParser_Node_Stmt_If($cond, array(
                            'stmts' => $ifBranch,
                            'else' => new PHPParser_Node_Stmt_Else($elseBranch)
                        ))), $state);
                    }, $state);
                };
                
                if ($ifTemp) {
                    return $ifFinal(generateReturn($ifTemp, $state), $state);
                }
                
                $ifNext = function ($if, $final, $state) {
                    return $final(generateReturn($if, $state), $state);
                };
                if ($next instanceof ReturnClosure) {
                    $ifNext = new ReturnClosure($next);
                }
            
                return traverseNode($if, $ifNext, $ifFinal, $state);
            }, generateFinalForContinuation($final, $continuation), $state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_BooleanAnd || // short circuit binary operators
        $node instanceof PHPParser_Node_Expr_LogicalAnd ||
        $node instanceof PHPParser_Node_Expr_BooleanOr ||
        $node instanceof PHPParser_Node_Expr_LogicalOr)
    {
        $short_circuit_if_left_is = true;
        if ($node instanceof PHPParser_Node_Expr_BooleanAnd || $node instanceof PHPParser_Node_Expr_LogicalAnd) {
            $short_circuit_if_left_is = false;
        }

        return generateContinuation($next, function ($continuation, $state) use ($next, $node, $final, $short_circuit_if_left_is) {
            $right = $node->right;
            return traverseNode($node->left, function ($left, $final, $state) use ($next, $right, $short_circuit_if_left_is) {
                $leftTemp = assignToTemp($left, $stmts);
                
                $rightNext = function ($right, $final, $state) {
                    return $final(generateReturn(boolifyLogicalOperator($right), $state), $state);
                };
                
                if ($next instanceof ReturnClosure) {
                    $rightNext = new ReturnClosure($rightNext);
                }
                
                return traverseNode($right, $rightNext, function ($rightBranch, $state) use ($stmts, $final, $leftTemp, $short_circuit_if_left_is) {                
                    $leftBranch = generateReturn(boolifyLogicalOperator($leftTemp), $state);

                    $if = $rightBranch;
                    $else = $leftBranch;
                    if ($short_circuit_if_left_is) {
                        $if = $leftBranch;
                        $else = $rightBranch;
                    }
                    
                    $stmts[] = new PHPParser_Node_Stmt_If($leftTemp, array(
                        'stmts' => $if,
                        'else' => new PHPParser_Node_Stmt_Else($else)
                    ));
                    return $final($stmts, $state);
                }, $state);
            }, generateFinalForContinuation($final, $continuation), $state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_BitwiseAnd || // binary expressions
        $node instanceof PHPParser_Node_Expr_BitwiseOr ||
        $node instanceof PHPParser_Node_Expr_BitwiseXor ||
        $node instanceof PHPParser_Node_Expr_Concat ||
        $node instanceof PHPParser_Node_Expr_Div ||
        $node instanceof PHPParser_Node_Expr_Equal ||
        $node instanceof PHPParser_Node_Expr_GreaterOrEqual ||
        $node instanceof PHPParser_Node_Expr_Greater ||
        $node instanceof PHPParser_Node_Expr_Identical ||
        $node instanceof PHPParser_Node_Expr_LogicalXor ||
        $node instanceof PHPParser_Node_Expr_Minus ||
        $node instanceof PHPParser_Node_Expr_Mod ||
        $node instanceof PHPParser_Node_Expr_Mul ||
        $node instanceof PHPParser_Node_Expr_NotEqual ||
        $node instanceof PHPParser_Node_Expr_NotIdentical ||
        $node instanceof PHPParser_Node_Expr_Plus ||
        $node instanceof PHPParser_Node_Expr_ShiftLeft ||
        $node instanceof PHPParser_Node_Expr_ShiftRight ||
        $node instanceof PHPParser_Node_Expr_SmallerOrEqual ||
        $node instanceof PHPParser_Node_Expr_Smaller)
    {
        $node_class = get_class($node);
        $node_right = $node->right;
        return traverseNode($node->left, function ($left, $final, $state) use ($node_class, $node_right, $next) {
            return traverseNode($node_right, function ($right, $final, $state) use ($left, $next, $node_class) {
                return $next(new $node_class($left, $right), $final, $state);
            }, $final, $state);
        }, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_Assign || // assignments
        $node instanceof PHPParser_Node_Expr_AssignBitwiseAnd ||
        $node instanceof PHPParser_Node_Expr_AssignBitwiseOr ||
        $node instanceof PHPParser_Node_Expr_AssignBitwiseXor ||
        $node instanceof PHPParser_Node_Expr_AssignConcat ||
        $node instanceof PHPParser_Node_Expr_AssignDiv ||
        $node instanceof PHPParser_Node_Expr_AssignMinus ||
        $node instanceof PHPParser_Node_Expr_AssignMod ||
        $node instanceof PHPParser_Node_Expr_AssignMul ||
        $node instanceof PHPParser_Node_Expr_AssignPlus ||
        $node instanceof PHPParser_Node_Expr_AssignRef ||
        $node instanceof PHPParser_Node_Expr_AssignShiftLeft ||
        $node instanceof PHPParser_Node_Expr_AssignShiftRight)
    {
        $node_class = get_class($node);
        $expr = $node->expr;
        return traverseNode($node->var, function ($var, $final, $state) use ($node_class, $expr, $next) {
            return traverseNode($expr, function ($expr, $final, $state) use ($var, $next, $node_class) {
                return $next(new $node_class($var, $expr), $final, $state);
            }, $final, $state);
        }, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_AssignList) {
        $expr = $node->expr;
        return call_user_func($loop = function ($vars, $compiled_vars, $final, $state) use (&$loop, $expr, $next) {
            if (count($vars)) {
                $var = array_shift($vars);
                return function () use (&$loop, $var, $vars, $final, $state, $compiled_vars) {
                    return traverseNode($var, function ($var, $final, $state) use (&$loop, $vars, $compiled_vars) {
                        $compiled_vars[] = $var;
                        return $loop($vars, $compiled_vars, $final, $state);
                    }, $final, $state);
                };
            }
            else {
                return traverseNode($expr, function ($expr, $final, $state) use ($compiled_vars, $next) {
                    return $next(new PHPParser_Node_Expr_AssignList($compiled_vars, $expr), $final, $state);
                }, $final, $state);
            }
        }, $node->vars, array(), $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_ArrayDimFetch) {
        $dim = $node->dim;
        return traverseNode($node->var, function ($var, $final, $state) use ($dim, $next) {
            return traverseNode($dim, function ($dim, $final, $state) use ($var, $next) {
                return $next(new PHPParser_Node_Expr_ArrayDimFetch($var, $dim), $final, $state);
            }, $final, $state);
        }, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_Variable) {
        return traverseNode($node->name, function ($name, $final, $state) use ($next) {
            if ($name instanceof PHPParser_Node_Scalar_String) {
                $name = $name->value;
            }
            if (is_string($name)) {
                if (in_array($name, $GLOBALS['SUPERGLOBALS'])) {
                    // Directly access superglobals. Don't go through the locals.
                    return $next(new PHPParser_Node_Expr_Variable($name), $final, $state);
                }

                $name = new PHPParser_Node_Scalar_String($name);
            }
            return $next(new PHPParser_Node_Expr_ArrayDimFetch(
                new PHPParser_Node_Expr_Variable(LOCALS_NAME),
                $name
            ), $final, $state);
        }, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_Instanceof) {
        return traverseNode($node->expr, function ($expr, $final, $state) use ($next, $node) {
            return traverseNode($node->class, function ($class, $final, $state) use ($next, $expr) {
                return $next(new PHPParser_Node_Expr_Instanceof($expr, $class), $final, $state);
            }, $final, $state);
        }, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Interface) {
        return traverseStatements($node->stmts, function ($stmts, $new_state) use ($final, $next, $state, $node) {
            return $next(new PHPParser_Node_Stmt_Interface(
                $node->name,
                array(
                    'extends' => $node->extends,
                    'stmts' => $stmts
                )
            ), $final, $state);
        }, array(), new FunctionState(), true);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Class) {
        $new_state = new FunctionState();
        $new_state->setSelf($node->name);
        $new_state->setParent($node->extends);

        $builtin_methods = array();
        if ($node->extends && array_key_exists($node->extends->toString(), $GLOBALS['CLASS_TO_BUILTIN_METHODS'])) {
            $builtin_methods = $GLOBALS['CLASS_TO_BUILTIN_METHODS'][$node->extends->toString()];
            
            $GLOBALS['CLASS_TO_BUILTIN_METHODS'][$node->name] = $builtin_methods;
            // Find non-builtins that are defined in this class
            foreach ($node->stmts as $method) {
                if ($method instanceof PHPParser_Node_Stmt_ClassMethod) {
                    unset($GLOBALS['CLASS_TO_BUILTIN_METHODS'][$node->name][$method->name]);
                }
            }
            
            foreach ($GLOBALS['CLASS_TO_BUILTIN_METHODS'][$node->name] as $method_name => $junk) {
                $new_state->addBuiltinMethod($method_name);
            }
        }

        return traverseStatements($node->stmts, function ($stmts, $new_state) use ($final, $next, $state, $node) {
            $result = $new_state->generateBuiltinMethodDeclarations();
            $result[] = new PHPParser_Node_Stmt_Class(
                $node->name,
                array(
                    'type' => $node->type,
                    'extends' => $node->extends,
                    'implements' => $node->implements,
                    'stmts' => $stmts
                )
            );
            return $next($result, $final, $state);
        }, array(), $new_state, true);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_ClassMethod) {
        if (count($node->stmts) &&
            $node->stmts[0] instanceof PHPParser_Node_Scalar_String &&
            $node->stmts[0]->value == 'fast')
        {
            array_shift($node->stmts);
            __cps_add_builtin_method($state->getSelf(), $node->name);
            $state->addBuiltinMethod($node->name);
            return $next($node, $final, $state);
        }
    
        generateParams($node->params, $params, $param_items);
        
        $name = $node->name;
        
        $should_trampoline = false;
        if (in_array($name, $GLOBALS['MAGIC_METHODS'])) {
            $should_trampoline = true;
        }
        else {
            array_unshift($params, new PHPParser_Node_Param(EXCEPT_NAME));
            array_unshift($params, new PHPParser_Node_Param(CONT_NAME));
        }
        
        $new_state = new FunctionState();
        $new_state->setSelf($state->getSelf());
        $new_state->setParent($state->getParent());
        $new_state->setIsReturnByRef($node->byRef);
        $new_state->setIsInInstanceMethod(!($node->type & PHPParser_Node_Stmt_Class::MODIFIER_STATIC));
        
        return traverseStatements($node->stmts, function ($result, $new_state) use ($next, $final, $state, $node, $name, $params, $param_items, $should_trampoline) {
            if (isset($result)) {
                if ($should_trampoline) {
                    $result = generateTrampoline($result);
                }
                $param_items[] = new PHPParser_Node_Expr_ArrayItem(new PHPParser_Node_Expr_Variable('this'), new PHPParser_Node_Scalar_String('this'));
                $result = array_merge(
                    array(
                        new PHPParser_Node_Expr_Assign(
                            new PHPParser_Node_Expr_Variable(LOCALS_NAME),
                            new PHPParser_Node_Expr_Array($param_items)
                        ),
                        new PHPParser_Node_Expr_Assign(
                            new PHPParser_Node_Expr_Variable(TEMP_NAME),
                            generateDefaultTemps()
                        ),
                        new PHPParser_Node_Expr_Assign(
                            new PHPParser_Node_Expr_Variable(LABELS_NAME),
                            new PHPParser_Node_Expr_Array()
                        )
                    ),
                    $result);
            }
            
            return $next(new PHPParser_Node_Stmt_ClassMethod($name, array(
                'type' => $node->type,
                'params' => $params,
                'stmts' => $result,
                'byRef' => $should_trampoline && $new_state->getIsReturnByRef()
            )), $final, $state);
        }, generateReturn(new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name('null')), $new_state), $new_state, true);
    }
    elseif ($node instanceof PHPParser_Node_Expr_MethodCall) {
        return generateContinuation($next, function ($continuation, $state) use ($node, $final) {
            return traverseNode($node->var, function ($var, $final, $state) use ($node) {
                $args = $node->args;
                return traverseNode($node->name, function ($name, $final, $state) use ($args, $var) {
                    return call_user_func($loop = function ($final, $args, $compiled_args, $state) use (&$loop, $var, $name) {
                        if ($arg = array_shift($args)) {
                            return function () use (&$loop, $arg, $args, $compiled_args, $state, $final) {
                                $byRef = $arg->byRef;
                                return traverseNode($arg->value, function ($arg_value, $final, $state) use (&$loop, $byRef, $args, $compiled_args) {
                                    $compiled_args[] = new PHPParser_Node_Arg($arg_value, $byRef);
                                    return $loop($final, $args, $compiled_args, $state);
                                }, $final, $state);
                            };
                        }
                        else {
                            return $final(generateMethodCall($var, $name, $compiled_args, PHPParser_Node_Expr_MethodCall, $state), $state);
                        }
                    }, $final, $args, array(), $state);
                }, $final, $state);
            }, generateFinalForContinuation($final, $continuation), $state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_New) {
        return generateContinuation($next, function ($continuation, $state) use ($final, $node) {
            return traverseNode($node->class, function ($class, $final, $state) use ($node) {
                return call_user_func($loop = function ($args, $compiled_args, $final, $state) use (&$loop, $class) {
                    if ($arg = array_shift($args)) {
                        $byRef = $arg->byRef;
                        return traverseNode($arg->value, function ($arg, $final, $state) use (&$loop, $args, $byRef, $compiled_args) {
                            $compiled_args[] = new PHPParser_Node_Arg($arg, $byRef);
                            return function () use (&$loop, $args, $compiled_args, $final, $state) {
                                return $loop($args, $compiled_args, $final, $state);
                            };
                        }, $final, $state);
                    }
                    else {
                        $stmts = array(generateTryCatchCall(new PHPParser_Node_Expr_New($class, $compiled_args), $state));
                    
                        return $final(array(generateThunk(
                            array(
                                new PHPParser_Node_Expr_ClosureUse(CONT_NAME),
                                new PHPParser_Node_Expr_ClosureUse(LOCALS_NAME, true),
                                new PHPParser_Node_Expr_ClosureUse(TEMP_NAME)
                            ),
                            $stmts,
                            'new'
                        )), $state);
                    }
                }, $node->args, array(), $final, $state);
            }, generateFinalForContinuation($final, $continuation), $state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_PropertyFetch) {
        $name = $node->name;
        return traverseNode($node->var, function ($var, $final, $state) use ($next, $name) {
            return traverseNode($name, function ($name, $final, $state) use ($next, $var) {
                return $next(new PHPParser_Node_Expr_PropertyFetch($var, $name), $final, $state);
            }, $final, $state);
        }, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_StaticPropertyFetch) {
        $name = $node->name;
        return traverseNode($node->class, function ($class, $final, $state) use ($next, $name) {
            return traverseNode($name, function ($name, $final, $state) use ($next, $class) {
                return $next(new PHPParser_Node_Expr_StaticPropertyFetch($class, $name), $final, $state);
            }, $final, $state);
        }, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_StaticCall) {
        return generateContinuation($next, function ($continuation, $state) use ($final, $node) {
            return traverseNode($node->class, function ($class, $final, $state) use ($node) {
                return traverseNode($node->name, function ($name, $final, $state) use ($node, $class) {
                    return call_user_func($loop = function ($args, $compiled_args, $final, $state) use (&$loop, $class, $name) {
                        if ($arg = array_shift($args)) {
                            $byRef = $arg->byRef;
                            return traverseNode($arg->value, function ($arg_value, $final, $state) use ($args, $compiled_args, &$loop, $byRef) {
                                $compiled_args[] = new PHPParser_Node_Arg($arg_value, $byRef);
                                return $loop($args, $compiled_args, $final, $state);
                            }, $final, $state);
                        }
                        else {
                            return $final(generateMethodCall($class, $name, $compiled_args, PHPParser_Node_Expr_StaticCall, $state), $state);
                        }
                    }, $node->args, array(), $final, $state);
                }, $final, $state);
            }, generateFinalForContinuation($final, $continuation), $state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Property) {
        $type = $node->type;
        return call_user_func($loop = function ($props, $compiled_props, $final, $state) use (&$loop, $next, $type) {
            if ($prop = array_shift($props)) {
                $name = $prop->name;
                return traverseNode($prop->default, function ($default, $final, $state) use (&$loop, $props, $compiled_props, $name) {
                    $compiled_props[] = new PHPParser_Node_Stmt_PropertyProperty($name, $default);
                    return $loop($props, $compiled_props, $final, $state);
                }, $final, $state);
            }
            else {
                return $next(new PHPParser_Node_Stmt_Property($type, $compiled_props), $final, $state);
            }
        }, $node->props, array(), $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_ClassConst ||
        $node instanceof PHPParser_Node_Expr_ClassConstFetch)
    {
        return $next($node, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_Throw) {
        return traverseNode($node->expr, function ($expr, $final, $state) use ($next) {
            return $final($state->generateThrow($expr), $state); // skip calling $next, since throw completes the function
        }, $final, $state);
    }
    elseif ($node instanceof PHPParser_Node_Stmt_TryCatch) {
        $after_num = $state->generateBlockNum();
        
        return call_user_func($loop = function ($catches, $compiled_catches) use (&$loop, $next, $final, $state, $after_num, $node) {
            if ($catch = array_shift($catches)) {
                return traverseStatements($catch->stmts, function ($catch_body) use (&$loop, $compiled_catches, $catches, $catch) {
                    array_unshift($catch_body, new PHPParser_Node_Expr_Assign(
                        new PHPParser_Node_Expr_ArrayDimFetch(
                            new PHPParser_Node_Expr_Variable(LOCALS_NAME),
                            new PHPParser_Node_Scalar_String($catch->var)
                        ),
                        new PHPParser_Node_Expr_Variable(VALUE_NAME)
                    ));
                    $compiled_catches[] = new PHPParser_Node_Stmt_If(
                        new PHPParser_Node_Expr_Instanceof(
                            new PHPParser_Node_Expr_Variable(VALUE_NAME),
                            $catch->type
                        ), array('stmts' => $catch_body));
                    
                    return $loop($catches, $compiled_catches);
                }, array(generateJump($after_num)), $state);
            }
            else {
                $compiled_catches = array_merge($compiled_catches, $state->generateThrow(new PHPParser_Node_Expr_Variable(VALUE_NAME)));
                return traverseStatements($node->stmts, function ($try_body, $new_state) use ($next, $final, $after_num, $state) {
                    return $next(null, function ($after_try, $state) use ($after_num, $final, $try_body) {
                        $state->addBasicBlock($after_num, $after_try);
                        return $final($try_body, $state);
                    }, $state);
                }, array(generateJump($after_num)), $state->addCatches($compiled_catches));
            }
        }, $node->catches, array());
    }
    elseif (false && $node instanceof PHPParser_Node_Expr_Include) {
        return generateContinuation($next, function ($continuation, $state) use ($final, $node) {
            return traverseNode($node->expr, function ($file, $final, $state) use ($node) {
                $is_once = ($node->type == PHPParser_Node_Expr_Include::TYPE_INCLUDE_ONCE) || ($node->type == PHPParser_Node_Expr_Include::TYPE_REQUIRE_ONCE);
                $is_require = ($node->type == PHPParser_Node_Expr_Include::TYPE_REQUIRE) || ($node->type == PHPParser_Node_Expr_Include::TYPE_REQUIRE_ONCE);
                $is_once = new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name($is_once ? 'true' : 'false'));
                $is_require = new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name($is_require ? 'true' : 'false'));
        
                return $final(array(new PHPParser_Node_Stmt_Return(
                    new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('eval' /* evil, but no more so than include */), array(
                        new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('_cps_include_eval'), array(
                            new PHPParser_Node_Arg($file),
                            new PHPParser_Node_Arg(new PHPParser_Node_Scalar_String($GLOBALS['compiler'])),
                            new PHPParser_Node_Arg($is_once),
                            new PHPParser_Node_Arg($is_require), 
                            new PHPParser_Node_Arg(new PHPParser_Node_Scalar_String($GLOBALS['file'])),
                            new PHPParser_Node_Arg(new PHPParser_Node_Scalar_LNumber($node->getLine()))
                        ))
                    ))
                )), $state);
            }, generateFinalForContinuation($final, $continuation), $state);
        }, $state);
    }
    elseif ($node instanceof PHPParser_Node_Expr_Include) {
        return generateContinuation($next, function ($continuation, $state) use ($final, $node) {
            return traverseNode($node->expr, function ($file, $final, $state) use ($node) {
                $is_once = ($node->type == PHPParser_Node_Expr_Include::TYPE_INCLUDE_ONCE) || ($node->type == PHPParser_Node_Expr_Include::TYPE_REQUIRE_ONCE);
                $is_require = ($node->type == PHPParser_Node_Expr_Include::TYPE_REQUIRE) || ($node->type == PHPParser_Node_Expr_Include::TYPE_REQUIRE_ONCE);
                $require = $is_require ? 'require' : 'include';
                $once = $is_once ? '_once' : '';
                $is_require = new PHPParser_Node_Expr_ConstFetch(new PHPParser_Node_Name($is_require ? 'true' : 'false'));
        
                return $final(array(new PHPParser_Node_Stmt_Return(
                    new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name($require . $once), array(
                        new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('_cps_include'), array(
                            new PHPParser_Node_Arg($file),
                            new PHPParser_Node_Arg(new PHPParser_Node_Scalar_String($GLOBALS['compiler'])),
                            new PHPParser_Node_Arg($is_require)
                        ))
                    ))
                )), $state);
            }, generateFinalForContinuation($final, $continuation), $state);
        }, $state);
    }
    else {
        throw new Exception('Unknown node type ' . get_class($node) . ' on line ' . $node->getLine());
    }
}

// Manual trampolining to unwind tail recursion in the compiler
function run($thunk) {
    $result_container = array();
    $cont = function ($result) use (&$result_container) {
        $result_container[] = $result;
    };
    while ($thunk) {
        $thunk = $thunk($cont);
        if (count($result_container) > 0) {
            return $result_container[0];
        }
    }
}

// Top level way to compile code.
// Returns a transformed tree.
function compile($code) {
    $parser = new PHPParser_Parser();
    try {
        $stmts = $parser->parse(new PHPParser_Lexer($code));
    }
    catch (PHPParser_Error $e) {
        echo "\nParse error: " . $e->getMessage() . ' in ' . $GLOBALS['file'] . "\n";
        exit(255);
    }
    
    return run(function ($cont) use ($stmts) {
        $state = new FunctionState();
        return traverseStatements($stmts, $cont, generateReturn(new PHPParser_Node_Scalar_LNumber(1), $state), $state, true);
    });
}

function generateTrampoline($stmts) {
    return array(new PHPParser_Node_Stmt_Return(
        new PHPParser_Node_Expr_FuncCall(new PHPParser_Node_Name('_cps_trampoline'), array(
            new PHPParser_Node_Expr_Closure(array(
                'params' => array(
                    new PHPParser_Node_Param(CONT_NAME),
                    new PHPParser_Node_Param(EXCEPT_NAME)
                ),
                'uses' => array(
                    new PHPParser_Node_Expr_ClosureUse(LOCALS_NAME, true),
                    new PHPParser_Node_Expr_ClosureUse(TEMP_NAME),
                    new PHPParser_Node_Expr_ClosureUse(LABELS_NAME)
                ),
                'stmts' => $stmts
            ))
        ))
    ));
}

function generateThunk($uses, $stmts, $type) {
    $result = new PHPParser_Node_Stmt_Return(
        new PHPParser_Node_Expr_Closure(array(
            'uses' => $uses,
            'stmts' => $stmts
        ))
    );

    if ($max_stack = config('trampoline_max_stack')) {
        return new PHPParser_Node_Stmt_If(
            new PHPParser_Node_Expr_Smaller(
                new PHPParser_Node_Expr_PreInc(
                    new PHPParser_Node_Expr_ArrayDimFetch(
                        new PHPParser_Node_Expr_Variable('GLOBALS'),
                        new PHPParser_Node_Scalar_String('__cps_tsd')
                    )
                ),
                new PHPParser_Node_Scalar_LNumber(config('trampoline_max_stack'))
            ),
            array(
                'stmts' => $stmts,
                'else' => new PHPParser_Node_Stmt_Else(array(
                    new PHPParser_Node_Expr_Assign(
                        new PHPParser_Node_Expr_ArrayDimFetch(
                            new PHPParser_Node_Expr_Variable('GLOBALS'),
                            new PHPParser_Node_Scalar_String('__cps_tsd')
                        ),
                        new PHPParser_Node_Scalar_LNumber(0)
                    ),
                    $result
                ))
            )
        );
    }
    
    return $result;
}
