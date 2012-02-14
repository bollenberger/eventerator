<?php

// Helpers for asserting things
function prove($test) {
    echo $test ? '' : "!!! Failed\n";
}

$did_run = false;
function must($result) {
    global $did_run;
    $did_run = true;
    return $result;
}
function did_run() {
    global $did_run;
    $dr = $did_run;
    $did_run = false;
    return $dr;
}

// Test simple function call
function function_call() {
    return 'result';
}
prove('result' == function_call());

// Test recursion
function fact($n, $acc = 1) {
    return ($n <= 0) ? $acc : fact($n - 1, $acc * $n);
}
prove(120 == fact(5));

// Test continuation after return
function add_one($x) {
    return $x + 1;
}
$x = add_one($y = 2);
prove($y == 2);
prove($x == 3);

// Test isset(), including short circuiting

isset($z, ${must('x')});
prove(!did_run());
prove(!isset($z));
prove(isset($x));
prove(isset($x, ${must('y')}));
prove(did_run());

// Test && and || short circuiting
$x = true;
prove($x && must(true));
prove(did_run());
prove($x || must(true));
prove(!did_run());

// Test ternary operator and short circuiting
prove(true ? true : false);
prove(!(true ? false : true));
prove(!(false ? true : false));
prove(false ? false : true);
prove(true ? must(true) : false);
prove(did_run());
prove(true ? true : must(false));
prove(!did_run());
prove(false ? false : must(true));
prove(did_run());
prove(false ? must(false) : true);
prove(!did_run());

// Test pre and post increment semantics
$x = 0;
prove(++$x == 1);
prove($x++ == 1);
prove($x == 2);

// Test pass by reference
function increment(&$x) {
    ++$x;
}
$x = 0;
increment($x);
prove($x == 1);

// Test arrays
$x = array('a' => 1, 2);
prove(1 == $x['a']);
$x[] = 3;
prove(2 == $x[0]);
prove(3 == $x[1]);
$x[1] = 4;
prove(4 == $x[1]);

// Test superglobals
$x = '_SERVER';
prove(is_array($_SERVER));
prove(is_array($$x));
function superglobals() {
    $x = '_SERVER';
    prove(!isset($$x));
    prove(is_array(${'_SERVER'}));
}
superglobals();

// Test function hoisting
prove('hoisted' == hoist());
prove(did_run());
function hoist() {
    return must('hoisted');
}

// Test continuations
function continuation_test($cont) {
    $cont(5);
    return 4;
}
function noop($x) {}
prove(4 == continuation_test('noop'));
prove(5 == callcc('continuation_test'));
$c = callcc('callcc');
if (is_string($c)) {
    must(true);
    prove($c == 'abc');
}
else {
    $c('abc');
}
prove(did_run());

// Test goto
$x = 3;
goto add_one; // forward jump
times_two: {
    $x *= 2;
    goto done;
}
add_one: {
    ++$x;
    goto times_two; // backward jump
}
done: {
    prove(8 == $x); // wrong order would yield 7
}

// Test while
$x = 0;
while ($x < 2) {
    ++$x;
}
prove($x == 2);

// Test if

if (true) {
    must(true);
}
else {
    prove(false);
}
prove(did_run());

if (must(true)) {
    prove(did_run());
}
elseif (must(true)) {
    prove(false);
}
else{
    prove(false);
}
prove(!did_run());
if (false) {
    prove(false);
}
else {
    must(true);
}
prove(did_run());

// Test break and continue
$x = true;
while ($x) {
    $x = false;
    break;
    must(false);
}
prove(!did_run());
$x = 2;
$y = 0;
while ($x) {
    --$x;
    ++$y;
    continue;
    ++$y;
}
prove($y == 2);

while (true) {
    while (true) {
        must(true);
        break 2; // can't test failure cases, but this must terminate
    }
}
prove(did_run());

// Test return by reference
$x = 1;
function &return_by_ref1() {
    global $x;
    return $x;
}
$y =& return_by_ref1();
++$y;
prove($x == 2);

function foo() {
    return 5;
}
function &return_by_ref2() {
    return 5;//foo();
}
prove(5 == return_by_ref2());

// Test closures
$x = 1;
$f = function ($y) use ($x) {
    return $x + $y;
};
prove($f(2) == 3);
$x = 1;
$f = function ($x) use ($x) {
    return $x;
};
prove(1 == $f(2));
function make_adder($x) {
    return function ($y) use ($x) {
        return $x + $y;
    };
}
$f = make_adder(5);
prove($f(12) == 17);

// Test dynamic function calls
$isstr = 'is_';
$isstr .= 'string';
prove($isstr($isstr));
$incr = 'increment';
$x = 1;
$incr($x);
prove($x == 2);

// Test default parameters - any kind of dynamic params that need to be handled?
function default_param($x, $y = 3) {
    $x = $x + $y;
    return $x;
}
$x = 5;
prove(8 == default_param($x));
prove($x == 5);
$f = function ($x = 5) {
    return $x;
};
prove($f() == 5);

// Test assignment order of operations
$x = 5;
$f = function () use (&$x) {
    ++$x;
    return 'a';
};
$g = function () use (&$x) {
    $x *= 2;
    return 'b';
};
$b = 6;
${$f()} = ${$g()};
prove($a == 6);
prove($x == 12);

// Test classes
interface Fooable {
    function foo($a, &$b);
}

class Base implements Fooable {
    const MY_CONST = 42;

    private $x = MY_CONST;
    
    function getX() {
        return $this->x;
    }

    function foo($a, &$b) {
        return $b = $a + 5;
    }
}

class Derived extends Base {
    private $y;

    function __construct($y) {
        $this->y = $y;
        return 'construct!';
    }

    function foo($a, &$b) {
        return parent::foo($a, $b) + $this->y;
    }
}

prove(42 == Base::MY_CONST);
$d = Derived::__allocate(); // yes, really
prove('construct!' == $d->__initialize(5));
$d = new Derived(5);
$x = 7;
prove(11 == $d->foo(1, $x));
prove(6 == $x);

// Test foreach and order of operations
$order = array();
function keyname() {
    global $order;
    $order[] = 'k';
    return 'k';
}
function valuename() {
    global $order;
    $order[] = 'v';
    return 'v';
}
foreach (array('a' => 1, 'b' => 2) as ${keyname()} => ${valuename()}) {
    $order[] = $k;
    $order[] = $v;
}
prove(array_shift($order) == 'k');
prove(array_shift($order) == 'v');
prove(array_shift($order) == 'a');
prove(array_shift($order) == 1);
prove(array_shift($order) == 'k');
prove(array_shift($order) == 'v');
prove(array_shift($order) == 'b');
prove(array_shift($order) == 2);

// Test exception handling TODO-waiting on builtin classes for "Exception"

// Testing output operators and that the tests completed.
print "Do";
echo "ne.\n";