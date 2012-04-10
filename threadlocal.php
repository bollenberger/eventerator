<?php

class ThreadLocal {
    private static $context = null;
    
    private $vars = [];
    private $save_callbacks = [];
    private $restore_callbacks = [];
    
    public function __construct() {
        if ($old = self::$context) {
            $old->save();
        }
        self::$context = $this;
    }
    
    public static function getContext() {
        if (!self::$context) {
            self::$context = new ThreadLocal();
        }
        return self::$context;
    }
    
    public static function assign(&$ref, $value) {
        $ref = $value;
        self::getContext()->vars[] = [&$ref, $value];
    }
    
    public static function onSaveRestore($save, $restore) {
        $context = self::getContext();
        $context->save_callbacks[] = $save;
        $context->restore_callbacks[] = $restore;
    }
    
    public static function onSave($callback) {
        self::getContext()->save_callbacks[] = $callback;
    }
    
    public static function onRestore($callback) {
        self::getContext()->restore_callbacks[] = $callback;
    }
    
    private function save() {
        foreach ($this->vars as $var) {
            $var[1] = $var[0];
            $var[0] = null;
        }
        foreach ($this->save_callbacks as $callback) {
            call_user_func($callback);
        }
    }
    
    public function restore() {
        if (self::$context === $this) {
            return;
        }
        
        $old = self::getContext();
        $old->save();
        foreach ($this->vars as $var) {
            $var[0] = $var[1];
        }
        foreach ($this->restore_callbacks as $callback) {
            call_user_func($callback);
        }
        self::$context = $this;
    }
}