<?php

require_once('async.php');

function prove($condition) {
    echo $condition ? '' : '!!! Failed';
}

// Timer test
$state = 1;
Events::after(50, function () use (&$state) {
    $state *= 2;
});
Events::after(10, function () use (&$state) {
    $state += 1;
});
Events::after(100, function () use (&$state) {
    prove($state == 4);
    echo "1\n";
});

// Echo socket test
$listener = Events::listen('tcp://127.0.0.1:12345', function ($client) {
    $client->readline(function ($line) use ($client) {
        prove($line == "abc");
        $client->write($line . "\n");
    });
});
Events::connect('tcp://127.0.0.1:12345', function ($connection) use ($listener) {
    $connection->write("abc\r\n", function () use ($connection, $listener) {
        //$connection->flush();
        $connection->readline(function ($line) use ($connection, $listener) {
            prove($line == 'abc');
            echo "2\n";
            $connection->close();
            $listener->close();
        }, "\n");
    });
});

Events::yield();
