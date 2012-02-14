<?php

// Asyncronous HTTP server implementation for proxying

require('async.php');

class HttpServer {
    function __construct($events, $address, $app_class, $application) {
        $events->listen($address, function ($connection) use ($app_class, $application, $events) {
            $connection->readline(function ($request_line) use (&$connection, $app_class, $application, $events) {
                $parts = explode(' ', $request_line);
                if (count($parts) != 3) {
                    $connection->write("HTTP/1.0 400 Bad Request\r\n\r\n", function () use (&$connection) {
                        $connection->close();
                    });
                    return;
                }
                
                $method = $parts[0];
                $uri = $parts[1];
                $version = $parts[2];
                if ($version != 'HTTP/1.0') {
                    $connection->write("HTTP/1.0 505 HTTP Version Not Supported\r\n\r\nOnly HTTP/1.0 is supported", function () use (&$connection) {
                        $connection->close();
                    });
                    return;
                }
                
                $headers = array();
                call_user_func($read_header = function () use (&$read_header, &$connection, $method, $uri, &$headers, $app_class, $application, $events) {
                    $connection->readline(function ($header_line) use (&$read_header, &$connection, $method, $uri, &$headers, $app_class, $application, $events) {
                        if (strlen($header_line) > 0) {
                            $parts = explode(': ', $header_line, 2);
                            if (count($parts) == 2) {
                                $headers[$parts[0]][] = $parts[1];
                            }
                            call_user_func($read_header);
                        }
                        else {
                            $request = new $app_class($connection, $method, $uri, $headers, $events, $application);
                            $request->respond();
                        }
                    });
                });
            });
        });
    }
}

abstract class Request {
    private $connection;
    private $is_complete;
    protected $method;
    protected $uri;
    protected $headers;
    protected $events;
    protected $application;
    
    function __construct($connection, $method, $uri, $headers, $events, $application) {
        $this->connection = $connection;
        $this->method = $method;
        $this->uri = $uri;
        $this->headers = $headers;
        $this->events = $events;
        $this->application = $application;
        $this->is_complete = false;
        
        $this->connection->on_close(function () {
            if (!$this->is_complete) {
                $this->abort();
            }
        });
    }
    
    function complete() {
        $connection =& $this->connection;
        $is_complete =& $this->is_complete;
        $this->write('', function () {
            $this->is_complete = true;
            $this->connection->close();
        });
    }
    
    abstract function respond();
    
    abstract function abort();
    
    function read($length, $callback) {
        $this->connection->read($length, $callback);
    }
    
    function readline($callback, $delimiter = "\r\n") {
        $this->connection->readline($callback, $delimiter);
    }
    
    function write($data, $callback = null) {
        $this->connection->write($data, $callback);
    }
    
    function flush() {
        $this->connection->flush();
    }
}

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
            $this->events->after(1200, $ping);
        }));
    }
}

$events = new Events();
new HttpServer($events, 'tcp://127.0.0.1:8001', 'MyRequest', 'application');
$events->run();