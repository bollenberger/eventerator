<?php

require_once('http.php');

class MyRequest extends Request {
    private $is_aborted = false;

    function abort() {
        $this->is_aborted = true;
    }
    
    function while_active($callback) {
        $is_aborted =& $this->is_aborted;
        return function () use (&$is_aborted, $callback) {
            if (!$is_aborted) {
                call_user_func_array($callback, func_get_args());
            }
        };
    }
    
    function respond() {
        $data = "<table>" .
            "<tr><td>Method</td><td>{$this->method}</td></tr>" .
            "<tr><td>URI</td><td>{$this->uri}</td></tr>" .
            "<tr><td>Headers</td><td>" . print_r($this->headers, true) . "</td></tr>" .
            "<tr><td>Application</td><td>" . print_r($this->application, true) . "</td></tr></table>";
        $this->write("HTTP/1.0 200 OK\r\nContent-Type: text/html\r\n\r\n<html><body>$data\n", function () {
            $this->flush();
        });
        $count = 1;
        call_user_func($ping = $this->while_active(function () use (&$ping, &$count) {
            $this->write("ping! $count<br />");
            $count++;
            Events::after(1200, $ping);
        }));
    }
}

HttpServer('tcp://127.0.0.1:8001', 'MyRequest', 'application');
Events::yield();