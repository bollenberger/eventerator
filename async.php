<?php

/**
 * Asynchronous I/O library for PHP streams.
 **/

require_once('threadlocal.php');

class Events {
    private static $read_callbacks = [];
    private static $read_streams = [];
    private static $write_callbacks = [];
    private static $write_streams = [];
    private static $except_callbacks = [];
    private static $except_streams = [];
    private static $close_callbacks = [];
    private static $timers = [];
    private static $yield = [];

    public static function listen($address, $callback) {
        $socket = stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (false === $socket) {
            throw new Exception('unable to create server socket: ' . $errstr);
        }
        if (false === stream_set_blocking($socket, 0)) { // set non blocking
            throw new Exception('unable to set server stream non blocking');
        }
        
        self::on_read($socket, function () use ($socket, $callback) {
            $client = stream_socket_accept($socket);
            if (false === $client) {
                throw new Exception('unable to accept connection');
            }
            if (false === stream_set_blocking($client, 0)) { // set client non blocking
                throw new Exception('unable to set client stream non blocking');
            }
            
            call_user_func($callback, new IO($client));
        });
        
        return new IO($socket);
    }
    
    public static function connect($address, $callback) {
        $stream = stream_socket_client($address, $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT);
        if (false === $stream) {
            throw new Exception('unable to connect client socket: ' . $errstr);
        }
        if (false === stream_set_blocking($stream, 0)) {
            throw new Exception('unable to set client stream non blocking');
        }
        
        call_user_func($callback, new IO($stream));
    }
    
    public static function after($milliseconds, $callback) {
        $at = microtime(true) + $milliseconds / 1000;
        // Ordered insert
        $start = 0;
        $end = count(self::$timers) - 1;
        while ($start < $end) {
            $mid = ($start + $end) / 2;
            if ($at < self::$timers[$mid][0]) {
                $end = $mid;
            }
            else {
                $start = $mid;
            }
        }
        if ($start < count(self::$timers)) {
            if ($at > self::$timers[$start][0]) {
                ++$start;
            }
        }
        array_splice(self::$timers, $start, 0, [[$at, $callback, ThreadLocal::getContext()]]);
    }

    public static function on_read($stream, $callback) {
        self::$read_callbacks[$stream][] = [$callback, ThreadLocal::getContext()];
        self::$read_streams[$stream] = $stream;
    }
    
    public static function on_write($stream, $callback) {
        self::$write_callbacks[$stream][] = [$callback, ThreadLocal::getContext()];
        self::$write_streams[$stream] = $stream;
    }
    
    public static function on_except($stream, $callback) {
        self::$except_callbacks[$stream][] = [$callback, ThreadLocal::getContext()];
        self::$except_streams[$stream] = $stream;
    }
    
    public static function on_close($stream, $callback) {
        self::$close_callbacks[$stream][] = [$callback, ThreadLocal::getContext()];
    }
    
    public static function unregister_read($stream) {
        if (isset(self::$read_callbacks[$stream])) {
            array_shift(self::$read_callbacks[$stream]);
            if (count(self::$read_callbacks[$stream]) == 0) {
                unset(self::$read_streams[$stream]);
                unset(self::$read_callbacks[$stream]);
            }
        }
    }
    
    public static function unregister_write($stream) {
        if (isset(self::$write_callbacks[$stream])) {
            array_shift(self::$write_callbacks[$stream]);
            if (count(self::$write_callbacks[$stream]) == 0) {
                unset(self::$write_streams[$stream]);
                unset(self::$write_callbacks[$stream]);
            }
        }
    }
    
    public static function unregister_except($stream) {
        if (isset(self::$except_callbacks[$stream])) {
            array_shift(self::$except_callbacks[$stream]);
            if (count(self::$except_callbacks[$stream]) == 0) {
                unset(self::$except_streams[$stream]);
                unset(self::$except_callbacks[$stream]);
            }
        }
    }
    
    public static function close($stream) {
        if (isset(self::$close_callbacks[$stream])) {
            foreach (self::$close_callbacks[$stream] as $callback) {
                $callback[1]->restore();
                call_user_func($callback[0]);
            }
            unset(self::$close_callbacks[$stream]);
        }
        unset(self::$read_streams[$stream]);
        unset(self::$read_callbacks[$stream]);
        unset(self::$write_streams[$stream]);
        unset(self::$write_callbacks[$stream]);
        unset(self::$except_streams[$stream]);
        unset(self::$except_callbacks[$stream]);
        if (false === stream_socket_shutdown($stream, STREAM_SHUT_RDWR)) {
            throw new Exception('failed closing stream');
        }
    }
    
    private static function perform_callback($callback) {
        callcc(function ($yield) use ($callback) {
            self::$yield[] = $yield;
            try {
                call_user_func($callback);
            }
            catch (Exception $e) {
                fwrite(STDERR, "$e\r\n");
            }
        });
        array_pop(self::$yield);
    }
    
    // Run the event loop - treat this like calling a continuation. It should never return to the calling program.
    public static function yield() {
        if (count(self::$yield)) {
            $cont = end(self::$yield);
            $cont();
        }
        
        $is_first = true;
        while ($is_first || ($stream_count > 0 && false !== stream_select($read_streams, $write_streams, $except_streams, $tv_sec, $tv_usec)) || count(self::$timers) > 0) {
            if (!$is_first) {
                // Call relevant callbacks.
                $last_callback = null;
                if ($read_streams) {
                    foreach ($read_streams as $stream) {
                        $callbacks =& self::$read_callbacks[$stream];
                        $is_first = true;
                        while (count($callbacks) && ($this_callback = $callbacks[0]) && $this_callback[0] !== $last_callback) {
                            $last_callback = $this_callback[0];
                            self::perform_callback(function () use ($this_callback, $is_first) {
                                $this_callback[1]->restore();
                                call_user_func($this_callback[0], $is_first);
                            });
                            $is_first = false;
                        }
                    }
                }
                $last_callback = null;
                if ($write_streams) {
                    foreach ($write_streams as $stream) {
                        if (array_key_exists((int)$stream, self::$write_callbacks)) {
                            $callbacks =& self::$write_callbacks[$stream];
                            while (count($callbacks) && ($this_callback = $callbacks[0]) && $this_callback[0] !== $last_callback) {
                                $last_callback = $this_callback[0];
                                $this_callback[1]->restore();
                                self::perform_callback($this_callback[0]);
                            }
                        }
                    }
                }
                $last_callback = null;
                if ($except_streams) {
                    foreach ($except_streams as $stream) {
                        if (array_key_exists((int)$stream, self::$except_callbacks)) {
                            $callbacks =& self::$except_callbacks[$stream];
                            while (count($callbacks) && ($this_callback = $callbacks[0]) && $this_callback[0] !== $last_callback) {
                                $last_callback = $this_callback[0];
                                $this_callback[1]->restore();
                                self::perform_callback($this_callback[0]);
                            }
                        }
                    }
                }
                
                // Call timers
                $now = microtime(true);
                if ($stream_count == 0) {
                    // Wait for the first timer
                    if ($now < self::$timers[0][0]) {
                        usleep(self::$timers[0][0] - $now);
                    }
                    $now = $this->timers[0][0];
                }
                while (count(self::$timers) > 0) {
                    $timer = array_shift(self::$timers);
                    if ($timer[0] <= microtime(true)) {
                        $timer[2]->restore();
                        self::perform_callback($timer[1]);
                    }
                    else {
                        array_unshift(self::$timers, $timer); // put back for later
                        break;
                    }
                    $now = microtime(true);
                }
            }
            else {
                $is_first = false;
            }
        
            // Reset the sets of streams to select
            $read_streams = array_values(self::$read_streams);
            $write_streams = array_values(self::$write_streams);
            $except_streams = array_values(self::$except_streams);
            $stream_count = count($read_streams) + count($write_streams) + count($except_streams);
            $tv_sec = null;
            $tv_usec = null;
            if (count(self::$timers) > 0) {
                $time_until_next_timer = self::$timers[0][0] - microtime(true);
                if ($time_until_next_timer < 0) {
                    $tv_sec = $tv_usec = 0;
                }
                else {
                    $tv_sec = floor($time_until_next_timer);
                    $tv_usec = floor(($time_until_next_timer - $tv_sec) * 999999) + 1;
                }
            }
        }
        
        // If we get to the end of the event loop, terminate the program. This preserves
        // continuation semantics, even for an initial call into the event loop.
        exit(0);
    }
}

class IO {
    private $stream;
    private $data_in = [];
    private $data_in_count = 0;
    private $is_closed = false;
    
    public function __construct($stream) {
        $this->stream = $stream;
    }
    
    public function flush() {
        if (false === fflush($this->stream)) {
            throw new Exception('failed flushing');
        }
    }
    
    public function close() {
        if (!$this->is_closed) {
            $this->is_closed = true;
            Events::close($this->stream);
        }
    }
    
    public function on_close($callback) {
        return Events::on_close($this->stream, $callback);
    }
    
    // Register a callback to call repeatedly whenever data is read.
    // May interact unexpectedly with read and readline if used concurrently.
    public function on_data_in($callback) {
        $data_in =& $this->data_in;
        $data_in_count =& $this->data_in_count;
        
        Events::on_read($this->stream, function ($is_first) use (&$callback) {
            if ($is_first) {
                $data = fread($this->stream, 4096);
                $data_in[] = $data;
                ++$data_in_count;
            }
            
            if ($data_in_count > 0) {
                $data_in_count = 0;
                call_user_func($callback, implode($data_in));
            }
        });
    }
    
    public function unregister_on_data_in() {
        Events::unregister_read($this->stream);
    }
    
    public function readline($callback, $delimiter = "\r\n") {
        $data_in =& $this->data_in;
        $data_in_count =& $this->data_in_count;
        $data = implode($data_in);
        
        if (false !== ($pos = strpos($data, $delimiter))) {
            $data_in = [substr($data, $pos + strlen($delimiter))];
            $data_in_count = strlen($data_in[0]);
            call_user_func($callback, substr($data, 0, $pos));
        }
        
        Events::on_read($this->stream, function ($is_first) use ($callback, &$data_in, &$data_in_count, $delimiter) {
            if ($is_first) {
                $data  = fread($this->stream, 4096);
                if (false === $data) {
                    throw new Exception('error reading');
                }
                elseif (strlen($data) == 0) {
                    $this->close();
                }
                else {
                    $data_in[] = $data;
                    $data_in_count += strlen($data);
                }
            }
            
            $data = implode($data_in);
            if (false !== ($pos = strpos($data, $delimiter))) {
                Events::unregister_read($this->stream);
                $data_in = [substr($data, $pos + strlen($delimiter))];
                $data_in_count = strlen($data_in[0]);
                call_user_func($callback, substr($data, 0, $pos));
            }
            else {
                $data_in = [$data];
            }
        });
    }
    
    public function read($length, $callback) {
        $data_in =& $this->data_in;
        $data_in_count =& $this->data_in_count;
        
        if ($length <= $data_in_count) {
            $data = implode($data_in);
            $data_in = [substr($data, $length)];
            $data_in_count = strlen($data_in[0]);
            call_user_func($callback, substr($data, 0, $length));
        }
        
        Events::on_read($this->stream, function ($is_first) use ($length, $callback, &$data_in, &$data_in_count) {
            if ($is_first) {
                $data = fread($this->stream, 4096);
                if (false === $data) {
                    throw new Exception('error reading');
                }
                elseif (strlen($data) == 0) {
                    $this->close();
                }
                else {
                    $data_in[] = $data;
                    $data_in_count += strlen($data);
                }
            }
            
            if ($data_in_count >= $length || strlen($data) == 0) {
                Events::unregister_read($this->stream);
                
                $data = implode($data_in);
                $data_in = [substr($data, $length)];
                $data_in_count = strlen($data_in[0]);
                call_user_func($callback, substr($data, 0, $length));
            }
        });
    }
    
    public function write($data, $callback = null) {
        $is_closed =& $this->is_closed;
        if ($is_closed) {
            throw new Exception('writing to closed stream');
        }
        
        Events::on_write($this->stream, function () use (&$data, $callback, &$is_closed) {
            if (!$is_closed) {
                $length = @fwrite($this->stream, $data);
                if ($length == 0 && strlen($data) > 0) {
                    $this->close();
                }
                elseif (false === $length) {
                    throw new Exception('unable to write to stream');
                }
                elseif ($length < strlen($data)) {
                    $data = substr($data, $length);
                }
                else {
                    Events::unregister_write($this->stream);
                    if (isset($callback)) {
                        call_user_func($callback);
                    }
                }
            }
        });
    }
}