<?php

require_once('async.php');

$e = new Events();

$e->listen('tcp://127.0.0.1:8007', function ($client) use ($e) {
    $echo = function ($data) use (&$client, &$echo) {
        $client->write(json_encode($data) . "\n");
        $client->read(10, $echo);
    };
    $client->read(10, $echo);
});

$e->run();