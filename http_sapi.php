<?php

require_once('http.php');

class AsyncHttpSapi extends Request {
    public static $REQUEST;
    public static $EVENTS;
    
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
            //override_function($name, $args, "return call_user_func_array(array(AsyncHttpSapi::\$REQUEST, '$name'), func_get_args());");
            //rename_function('__overridden__', '__' . $name . '_asyncsapi_overridden__');
        }
    }
    
    function respond() {
    }
}

AsyncHttpSapi::override_functions();

$events = new Events();
new HttpServer($events, 'tcp://127.0.0.1:8001', 'AsyncHttpSapi', 'application');
$events->run();