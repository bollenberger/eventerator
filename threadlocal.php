<?php

class ThreadLocal {
    private static $locals = null;
    
    private $context = [];
    
    private function __construct() {
        
    }
    
    public static function restoreContext(ThreadLocal $context) {
        $old = self::$locals;
        $old->save();
        $context->restore();
        self::$locals = $context;
        return $old;
    }
    
    public static function newContext() {
        $old = self::$locals;
        self::$locals = new ThreadLocal();
        if ($old) {
            $old->save();
        }
        return $old;
    }
    
    public static function assign(&$ref, $value) {
        $ref = $value;
        self::$locals->context[] = [&$ref, $value];
    }
    
    private function save() {
        foreach ($this->context as $var) {
            $var[1] = $var[0];
            $var[0] = null;
        }
    }
    
    private function restore() {
        foreach ($this->context as $var) {
            $var[0] = $var[1];
        }
    }
}