<?php

require_once('async.php');

$e = new Events();

call_user_func($loop = function ($n) use ($e, &$loop) {
    echo "$n\n";
    $e->after(1000, function () use (&$loop, $n) {
        $loop($n + 1);
    });
}, 0);

call_user_func($loop2 = function ($n) use ($e, &$loop2) {
    echo "$n\n";
    $e->after(800, function () use (&$loop2, $n) {
        $loop2($n + 1);
    });
}, 1001);


$e->run();