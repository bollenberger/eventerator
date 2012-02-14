<?php

require_once('fcgi.php');

// Simulate a web server API environment for PHP requests, but
// with access to a low level event loop and the mid level request object.

class AsyncSAPI extends Request {
    const REQUEST = 'request';
    const EVENTS = 'events';
    
    public static $REQUEST;
    public static $EVENTS;

    private $headers_sent = false;
    private $http_reponse_code = 200;
    private $headers = array();
    private $cookies = array();
    
    private $is_async = false;
    private $abort_callbacks = array();

    function set_request_globals() {
        self::$REQUEST = $this;
        self::$EVENTS = $this->events;
    }

    static function override_functions() {
        $functions = array (
            'header' => '$string, $replace = true, $http_response_code = null',
            'header_remove' => '$name',
            'headers_list' => '',
            'headers_sent' => '&$file = null, &$line = null',
            'setcookie' => '$name, $value = null, $expire = 0, $path = null, $domain = null, $secure = false, $httponly = false',
            'setrawcookie' => '$name, $value = null, $expire = 0, $path = null, $domain = null, $secure = false, $httponly = false',
            'php_sapi_name' => '',
            );
        foreach ($functions as $name => $args) {
            override_function($name, $args, "return call_user_func_array(array(AsyncSAPI::\$REQUEST, '$name'), func_get_args());");
            rename_function('__overridden__', '__' . $name . '_asyncsapi_overridden__');
        }
    }
    
    function header($string, $replace = true, $http_response_code = null) {
        if ($this->headers_sent) {
            trigger_error('Cannot modify header information - headers already sent', PHP_E_USER_WARN);
            return;
        }
        
        if (strtoupper(substr($string, 0, 5)) == 'HTTP/') {
            $parts = explode($string);
            $this->http_response_code = (int)$parts[1];
        }
        else {
            $parts = explode(': ', $string);
            if ($replace) {
                $this->headers[$parts[0]] = array($parts[1]);
            }
            else {
                $this->headers[$parts[0]][] = $parts[1];
            }
        }
        if (isset($http_response_code)) {
            $this->http_response_code = $http_response_code;
        }
    }
    
    function header_remove($name) {
        if (isset($this->headers[$name])) {
            array_shift($this->headers[$name]);
        }
    }
    
    function headers_list() {
        $headers = array();
        foreach ($this->headers as $name => $values) {
            foreach ($values as $value) {
                $headers[] = $name . ': ' . $value;
            }
        }
        return $headers;
    }
    
    function headers_sent(&$file, &$line) {
        return $this->headers_sent;
    }
    
    function setcookie($name, $value, $expire, $path, $domain, $secure, $httponly) {
        return $this->setrawcookie($name, urlencode($value), $expire, $path, $domain, $secure, $httponly);
    }
    
    function setrawcookie($name, $value, $expire, $path, $domain, $secure, $httponly) {
        // TODO
    }
    
    function php_sapi_name() {
        return 'async-' . PHP_SAPI;
    }
    
    function async() {
        $this->is_async = true;
        ob_end_flush();
    }
    
    function write($output, $callback = null) {
        if (!$this->headers_sent) {
            // Write headers
            foreach (headers_list() as $header) {
                parent::write($header . "\r\n");
            }
            parent::write("\r\n");
            $this->headers_sent = true;
        }
        parent::write($output, $callback);
    }

    function respond() {
        $request = $this;
        $events = $this->events;
        
        ob_start(function ($output, $flags) use (&$request) {
            $request->write($output, function () use (&$request, $output) {
                $request->flush();
            });
        });
        
        $this->set_request_globals();
        
        include($this->params['SCRIPT_FILENAME']);
        
        if (!$this->is_async) {
            ob_end_flush();
            $this->complete(0);
        }
    }
    
    // Takes a function that takes a callback and returns an application status code. The status of the final callback will be passed back to the web server.
    function on_abort($callback) {
        $this->abort_callbacks[] = $callback;
    }
    
    function abort($callback) {
        $abort_callbacks = $this->abort_callbacks;
        $last_status = 0; // Default status is success
        call_user_func($do_next_callback = function () use (&$callback, &$abort_callbacks, &$do_next_callback) {
            if ($next_callback = array_shift($abort_callbacks)) {
                $last_status = call_user_func($next_callback, $do_next_callback);
            }
            else {
                call_user_func($callback, $last_status);
            }
        });
    }
}

AsyncSAPI::override_functions();

$events = new Events();
$fcgi = new FastCGI($events, 'tcp://127.0.0.1:9000', 'AsyncSAPI', 'application');
$events->run();