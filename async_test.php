<?php

require_once('async.php');

function prove($condition) {
    echo $condition ? '' : '!!! Failed';
}

$e = new Events();
$state = 1;
$e->after(50, function () use (&$state) {
    $state *= 2;
});
$e->after(10, function () use (&$state) {
    $state += 1;
});
$e->run();
prove($state == 4);
