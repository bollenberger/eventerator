<?php

require_once('http.php');

class HttpSapi extends Request {
    public static $REQUEST;
    
    function set_request_globals() {
        self::$REQUEST = $this;
    }
    
    function respond() {
    }
    
    function abort() {
    }
    
    // Overridden functions
    static function header($string, $replace = true, $http_response_code = null) {
    }
    
    static function header_remove($name) {
    }
    
    static function headers_list() {
    }
    
    static function headers_sent(&$file = null, &$line = null) {
    }
    
    static function setcookie($name, $value = null, $expire = 0, $path = null, $domain = null, $secure = false, $httponly = false) {
    }
    
    static function setrawcookie($name, $value = null, $expire = 0, $path = null, $domain = null, $secure = false, $httponly = false) {
    }
    
    static function php_sapi_name() {
        return 'async_http';
    }
    
    // Start server
    static function start($callback, $application = null, $address = 'tcp://127.0.0.1:8001') {
        HttpServer($address, self, array($callback, $application));
        Events::yield();
    }
}

$php_sapi_name = 'php_sapi_name';
echo $$php_sapi_name();