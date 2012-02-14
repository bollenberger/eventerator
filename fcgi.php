<?php

require_once('async.php');

class FastCGI {
    const LISTENSOCK_FILENO = 0;
    const VERSION_1 = 1;
    const HEADER_LEN = 8;
    
    const BEGIN_REQUEST = 1;
    const ABORT_REQUEST = 2;
    const END_REQUEST = 3;
    const PARAMS = 4;
    const STDIN = 5;
    const STDOUT = 6;
    const STDERR = 7;
    const DATA = 8;
    const GET_VALUES = 9;
    const GET_VALUES_RESULT = 10;
    const UNKNOWN_TYPE = 11;
    const MAXTYPE = UNKNOWN_TYPE;
    
    const NULL_REQUEST_ID = 0;
    
    const KEEP_CONN = 1;
    
    const RESPONDER = 1;
    const AUTHORIZER = 2;
    const FILTER = 3;
    
    const REQUEST_COMPLETE = 0;
    const CANT_MPX_CONN = 1;
    const OVERLOADED = 2;
    const UNKNOWN_ROLE = 3;
    
    const MAX_CONNS = 'FCGI_MAX_CONNS';
    const MAX_REQS = 'FCGI_MAX_REQS';
    const MPXS_CONNS = 'FCGI_MPXS_CONNS';
    
    private $application;
    private $app_class;
    private $events;
    
    // Default settings
    private $max_conns = 10;
    private $max_reqs = 10;
    private $mpxs_conns = 1;

    public function __construct($events, $address, $app_class, $application = null) {
        $this->app_class = $app_class;
        $this->application = $application;
        $this->events = $events;
        $fcgi =& $this;
        $events->listen($address, function ($stream) use (&$fcgi) {
            // Callback loop for handling incoming FastCGI records.
            call_user_func($handle_record = function () use (&$fcgi, &$handle_record, &$stream) {
                $fcgi->receive_record($stream, $handle_record);
            });
        });
    }
    
    static function decode_string_length($content, &$offset) {
        $result = ord($content[$offset]);
        if ($result >> 7) {
            $result = (($result & 0x7f) << 24) |
                (ord($content[$offset + 1]) << 16) |
                (ord($content[$offset + 2]) << 8) |
                ord($content[$offset + 3]);
            $offset += 3;
        }
        
        ++$offset;
        return $result;
    }
    
    static function decode_string($content, $length, &$offset) {
        $result = substr($content, $offset, $length);
        $offset += $length;
        return $result;
    }
    
    static function encode_string_length(&$str) {
        $length = strlen($str);
        if ($length >= (1 << 31)) {
            throw new Exception('name/value string too long: ' . substr($str, 0, 16) . '...');
        }
        elseif ($length >= (1 << 7)) {
            return chr((1 << 8) | ($length >> 24)) . chr($length >> 16) . chr($length >> 8) . chr($length);
        }
        else {
            return chr($length);
        }
    }
    
    static function decode_name_values($content) {
        $result = array();
        
        $content_length = strlen($content);
        $offset = 0;
        while ($offset < $content_length) {
            $name_length = self::decode_string_length($content, $offset);
            $value_length = self::decode_string_length($content, $offset);
            $name = self::decode_string($content, $name_length, $offset);
            $value = self::decode_string($content, $value_length, $offset);
            $result[$name] = $value;
        }
        
        return $result;
    }
    
    static function encode_name_values($name_to_value) {
        $result = array();
        
        foreach ($name_to_value as $name => $value) {
            $result[] = self::encode_string_length($name) .
                self::encode_string_length($value) .
                $name . $value;
        }
        
        return implode($result);
    }
    
    function send_get_values_result($stream, $values, $callback = null) {
        $result = array();
        foreach ($values as $key => $empty) {
            switch ($key) {
                case self::MAX_CONNS:
                    $result[$key] = $this->max_conns;
                    break;
                case self::MAX_REQS:
                    $result[$key] = $this->max_reqs;
                    break;
                case self::MPXS_CONNS:
                    $result[$key] = $this->mpxs_conns ? 1 : 0;
                    break;
            }
        }
        $content = self::encode_name_values($result);
        $this->send_record($stream, self::GET_VALUES_RESULT, self::NULL_REQUEST_ID, $content, $callback);
    }
    
    function send_unknown_type($stream, $type, $callback = null) {
        $content = chr($type) . str_repeat(chr(0), 7);
        self::send_record($stream, self::UNKNOWN_TYPE, self::NULL_REQUEST_ID, $content, $callback);
    }
    
    function send_end_request($stream, $request_id, $app_status, $protocol_status, $should_keep_conn, $callback = null) {
        $content = chr($app_status >> 24) . chr($app_status >> 16) . chr($app_status >> 8) . chr($app_status) . chr($protocol_status) . str_repeat(chr(0), 3);
        $this->send_record($stream, self::END_REQUEST, $request_id, $content, function () use (&$callback, &$stream, $should_keep_conn) {
            if (!$should_keep_conn) {
                $stream->close();
            }
            if (isset($callback)) {
                call_user_func($callback);
            }
        });
    }
    
    function send_stdout($stream, $request_id, $data, $callback = null) {
        $this->send_record($stream, self::STDOUT, $request_id, $data, $callback);
    }
    
    function send_stderr($stream, $request_id, $data, $callback = null) {
        $this->send_record($stream, self::STDERR, $request_id, $data, $callback);
    }
    
    function send_record($stream, $type, $request_id, $content, $callback = null) {
        $content_length = strlen($content);
        $padding_length = $content_length % 8;
        $stream->write(chr(self::VERSION_1) .
            chr($type) .
            chr($request_id >> 8) . chr($request_id) .
            chr($content_length >> 8) . chr($content_length) .
            chr($padding_length) . chr(0) .
            $content .
            str_repeat(chr(0), $padding_length),
            $callback);
    }
    
    function abort_request($stream, $request_id) {
        if ($request = $this->get_request($stream, $request_id)) {
            $flags = $request->flags();
            $fcgi =& $this;
            $request->abort(function ($app_status) use (&$stream, &$fcgi, $request_id, $flags) {
                $fcgi->send_end_request($stream, $request_id, $app_status, self::REQUEST_COMPLETE, $flags & self::KEEP_CONN);
            });
        }
    }
    
    function add_request($stream, $request_id, $flags) {
        $key = spl_object_hash($stream) . $request_id;
        $requests =& $this->requests;
        $requests[$key] = new $this->app_class($this, $stream, $request_id, $flags, $this->events, $this->application);
        $stream->on_close(function () use (&$requests, $key) {
            unset($requests[$key]);
        });
    }
    
    function get_request($stream, $request_id) {
        $key = spl_object_hash($stream) . $request_id;
        if (array_key_exists($key, $this->requests)) {
            return $this->requests[$key];
        }
    }
    
    function receive_record($stream, $callback) {
        $fcgi =& $this;
        $stream->read(self::HEADER_LEN, function($header) use (&$stream, &$callback, &$fcgi) {
            if (strlen($header) < FastCGI::HEADER_LEN) {
                return;
            }
        
            $version = ord($header[0]);
            if ($version != FastCGI::VERSION_1) {
                throw new Exception('FCGI version ' . $version . ' not recognized');
            }
            $type = ord($header[1]);
            if ($type > FastCGI::MAXTYPE) {
                throw new Exception('Unknown type ' . $type);
            }
            $request_id = (ord($header[2]) << 8) | ord($header[3]);
            $content_length = (ord($header[4]) << 8) | ord($header[5]);
            $padding_length = ord($header[6]);
            
            $stream->read($content_length + $padding_length, function ($content) use ($type, $request_id, $content_length, &$callback, &$fcgi, &$stream) {
                $content = substr($content, 0, $content_length);
                if (strlen($content) < $content_length) {
                    return; // premature end of input. give up on it.
                }
                
                switch ($type) {
                    case FastCGI::GET_VALUES:
                        if ($request_id != self::NULL_REQUEST_ID) {
                            throw new Exception('GET_VALUES for non-NULL request ID');
                        }
                        $names = self::decode_name_values($content);
                        $fcgi->send_get_values_result($stream, $names);
                        break;
                    case FastCGI::BEGIN_REQUEST:
                        $role = (ord($content[0]) << 8) | ord($content[1]);
                        $flags = ord($content[2]);
                        
                        if ($role != FastCGI::RESPONDER) {
                            $this->send_end_request($stream, $request_id, 0, self::UNKNOWN_ROLE, $flags & FastCGI::KEEP_CONN);
                        }
                        else {
                            $fcgi->add_request($stream, $request_id, $flags);
                        }
                        break;
                    case FastCGI::ABORT_REQUEST:
                        $fcgi->abort_request($stream, $request_id);
                        break;
                    case FastCGI::PARAMS:
                        $values = FastCGI::decode_name_values($content);
                        if ($request = $fcgi->get_request($stream, $request_id)) {
                            $request->params($values);
                            if (count($values) == 0) {
                                $request->respond();
                            }
                        }
                        break;
                    case FastCGI::STDIN:
                        if ($request = $fcgi->get_request($stream, $request_id)) {
                            $request->stdin($content);
                        }
                        break;
                    case FastCGI::DATA:
                        // Theoretically, treat this similarly to STDIN, but we don't use it.
                        throw new Exception('unexpected DATA FastCGI message');
                        break;
                    default:
                        if ($request_id == FastCGI::NULL_REQUEST_ID) {
                            $fcgi->send_unknown_type($stream, $type);
                        }
                        throw new Exception('Bad application record type ' . $type);
                        break;
                }
                
                call_user_func($callback, $type, $request_id, $content);
            });
        });
        
    }
}

abstract class Request {
    protected $application = null;
    protected $events;
    protected $params = array();
    private $fcgi;
    private $stream;
    private $request_id;
    private $flags;
    private $stdin = '';
    private $reads = array();
    
    function __construct($fcgi, $stream, $request_id, $flags, $events, $application) {
        $this->fcgi = $fcgi;
        $this->stream = $stream;
        $this->request_id = $request_id;
        $this->flags = $flags;
        $this->events = $events;
        $this->application = $application;
    }
    
    function flags() {
        return $this->flags;
    }
    
    function params($params) {
        $this->params = array_merge($this->params, $params);
    }
    
    function complete($app_status = 0, $callback = null) {
        $this->fcgi->send_end_request($this->stream, $this->request_id, $app_status, FastCGI::REQUEST_COMPLETE, $this->flags & FastCGI::KEEP_CONN, $callback);
    }
    
    abstract function respond();
    
    abstract function abort($callback);
    
    function stdin($data) {
        $this->stdin .= $data;
        $read = null;
        while (count($this->reads)) {
            $read = array_shift($this->reads);
            $condition = $read[0];
            $callback = $read[1];
            if (is_string($condition) && (false !== ($pos = strpos($this->stdin, $condition)))) {
                call_user_func($callback, substr($this->stdin, 0, $pos));
                $this->stdin = substr($this->stdin, $pos + strlen($condition));
            }
            elseif (!is_string($condition) && strlen($this->stdin) >= $condition) {
                call_user_func($callback, substr($this->stdin, 0, $condition));
                $this->stdin = substr($this->stdin, $condition);
            }
            else {
                break;
            }
        }
        if ($read !== null) {
            array_unshift($this->reads, $read);
        }
    }
    
    function readline($callback, $endline = "\r\n") {
        $this->read($endline, $callback);
    }
    
    function read($length, $callback) {
        $this->reads[] = array($length, $callback);
        if (count($this->reads) == 1) {
            $this->stdin('');
        }
    }
    
    function write($data, $callback = null) {
        $this->fcgi->send_stdout($this->stream, $this->request_id, $data, $callback);
    }
    
    function flush() {
        $this->stream->flush();
    }
    
    function error($data, $callback = null) {
        $this->fcgi->send_stderr($this->stream, $this->request_id, $data, $callback);
    }
}