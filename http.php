<?php

// Asyncronous HTTP server implementation for proxying

require('async.php');

function HttpServer($address, $app_class, $application) {
    Events::listen($address, function ($connection) use ($app_class, $application) {
        $connection->readline(function ($request_line) use (&$connection, $app_class, $application) {
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
            call_user_func($read_header = function () use (&$read_header, &$connection, $method, $uri, &$headers, $app_class, $application) {
                $connection->readline(function ($header_line) use (&$read_header, &$connection, $method, $uri, &$headers, $app_class, $application) {
                    if (strlen($header_line) > 0) {
                        $parts = explode(': ', $header_line, 2);
                        if (count($parts) == 2) {
                            $headers[$parts[0]][] = $parts[1];
                        }
                        call_user_func($read_header);
                    }
                    else {
                        $request = new $app_class($connection, $method, $uri, $headers, $application);
                        $request->respond();
                    }
                });
            });
        });
    });
}

abstract class Request {
    private $connection;
    private $is_complete;
    protected $method;
    protected $uri;
    protected $headers;
    protected $application;
    
    function __construct($connection, $method, $uri, $headers, $application) {
        $this->connection = $connection;
        $this->method = $method;
        $this->uri = $uri;
        $this->headers = $headers;
        $this->application = $application;
        $this->is_complete = false;
        
        $this->connection->on_close(function () {
            if (!$this->is_complete) {
                $this->abort();
            }
        });
    }
    
    function complete() {
        $this->write('', function () {
            if (!$this->is_complete) {
                $this->is_complete = true;
                $this->connection->close();
            }
        });
    }
    
    abstract public function respond();
    
    abstract protected function abort();
    
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
