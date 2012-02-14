<?php

/**
 * Asynchronous I/O library for PHP streams.
 **/

class Events {
    private $read_callbacks = array();
    private $read_streams = array();
    private $write_callbacks = array();
    private $write_streams = array();
    private $except_callbacks = array();
    private $except_streams = array();
    private $close_callbacks = array();
    private $timers = array();

    function listen($address, $callback) {
        $socket = stream_socket_server($address, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if (false === $socket) {
            throw new Exception('unable to create server socket: ' . $errstr);
        }
        if (false === stream_set_blocking($socket, 0)) { // set non blocking
            throw new Exception('unable to set server stream non blocking');
        }
        
        $this->on_read($socket, function () use ($socket, $callback) {
            $client = stream_socket_accept($socket);
            if (false === $client) {
                throw new Exception('unable to accept connection');
            }
            if (false === stream_set_blocking($client, 0)) { // set client non blocking
                throw new Exception('unable to set client stream non blocking');
            }
            
            call_user_func($callback, new IO($client, $this));
        });
    }
    
    function connect($address, $callback) {
        $stream = stream_socket_client($address, $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT);
        if (false === $stream) {
            throw new Exception('unable to connect client socket: ' . $errstr);
        }
        if (false === stream_set_blocking($stream, 0)) {
            throw new Exception('unable to set client stream non blocking');
        }
        
        call_user_func($callback, new IO($stream, $this));
    }
    
    function after($milliseconds, $callback) {
        $at = microtime(true) + $milliseconds / 1000;
        // Ordered insert
        $start = 0;
        $end = count($this->timers) - 1;
        while ($start < $end) {
            $mid = ($start + $end) / 2;
            if ($at < $this->timers[$mid][0]) {
                $end = $mid;
            }
            else {
                $start = $mid;
            }
        }
        if ($start < count($this->timers)) {
            if ($at > $this->timers[$start][0]) {
                ++$start;
            }
        }
        array_splice($this->timers, $start, 0, array(array($at, $callback)));
    }

    function on_read($stream, $callback) {
        $this->read_callbacks[$stream][] = $callback;
        $this->read_streams[$stream] = $stream;
    }
    
    function on_write($stream, $callback) {
        $this->write_callbacks[$stream][] = $callback;
        $this->write_streams[$stream] = $stream;
    }
    
    function on_except($stream, $callback) {
        $this->except_callbacks[$stream][] = $callback;
        $this->except_streams[$stream] = $stream;
    }
    
    function on_close($stream, $callback) {
        $this->close_callbacks[$stream][] = $callback;
    }
    
    function unregister_read($stream) {
        if (isset($this->read_callbacks[$stream])) {
            array_shift($this->read_callbacks[$stream]);
            if (count($this->read_callbacks[$stream]) == 0) {
                unset($this->read_streams[$stream]);
                unset($this->read_callbacks[$stream]);
            }
        }
    }
    
    function unregister_write($stream) {
        if (isset($this->write_callbacks[$stream])) {
            array_shift($this->write_callbacks[$stream]);
            if (count($this->write_callbacks[$stream]) == 0) {
                unset($this->write_streams[$stream]);
                unset($this->write_callbacks[$stream]);
            }
        }
    }
    
    function unregister_except($stream) {
        if (isset($this->except_callbacks[$stream])) {
            array_shift($this->except_callbacks[$stream]);
            if (count($this->except_callbacks[$stream]) == 0) {
                unset($this->except_streams[$stream]);
                unset($this->except_callbacks[$stream]);
            }
        }
    }
    
    function close($stream) {
        if (isset($this->close_callbacks[$stream])) {
            foreach ($this->close_callbacks[$stream] as $callback) {
                call_user_func($callback);
            }
            unset($this->close_callbacks[$stream]);
        }
        unset($this->read_streams[$stream]);
        unset($this->read_callbacks[$stream]);
        unset($this->write_streams[$stream]);
        unset($this->write_callbacks[$stream]);
        unset($this->except_streams[$stream]);
        unset($this->except_callbacks[$stream]);
        if (false === stream_socket_shutdown($stream, STREAM_SHUT_RDWR)) {
            throw new Exception('failed closing stream');
        }
    }
    
    // Run the event loop
    function run() {
        $is_first = true;
        while ($is_first || ($stream_count > 0 && false !== stream_select($read_streams, $write_streams, $except_streams, $tv_sec, $tv_usec)) || count($this->timers) > 0) {
            if (!$is_first) {
                // Call relevant callbacks.
                $last_callback = null;
                if ($read_streams) {
                    foreach ($read_streams as $stream) {
                        $callbacks =& $this->read_callbacks[$stream];
                        $is_first = true;
                        while (count($callbacks) && ($this_callback = $callbacks[0]) !== $last_callback) {
                            $last_callback = $this_callback;
                            try {
                                call_user_func($this_callback, $is_first);
                            }
                            catch (Exception $e) {
                                fwrite(STDERR, "$e\r\n");
                            }
                            $is_first = false;
                        }
                    }
                }
                $last_callback = null;
                if ($write_streams) {
                    foreach ($write_streams as $stream) {
                        if (array_key_exists((int)$stream, $this->write_callbacks)) {
                            $callbacks =& $this->write_callbacks[$stream];
                            while (count($callbacks) && ($this_callback = $callbacks[0]) !== $last_callback) {
                                $last_callback = $this_callback;
                                try {
                                    call_user_func($this_callback);
                                }
                                catch (Exception $e) {
                                    fwrite(STDERR, "$e\r\n");
                                }
                            }
                        }
                    }
                }
                $last_callback = null;
                if ($except_streams) {
                    foreach ($except_streams as $stream) {
                        if (array_key_exists((int)$stream, $this->except_callbacks)) {
                            $callbacks =& $this->except_callbacks[$stream];
                            while (count($callbacks) && ($this_callback = $callbacks[0]) !== $last_callback) {
                                $last_callback = $this_callback;
                                try {
                                    call_user_func($this_callback);
                                }
                                catch (Exception $e) {
                                    fwrite(STDERR, "$e\r\n");
                                }
                            }
                        }
                    }
                }
                
                // Call timers
                $now = microtime(true);
                if ($stream_count == 0) {
                    // Wait for the first timer
                    if ($now < $this->timers[0][0]) {
                        usleep($this->timers[0][0] - $now);
                    }
                    $now = $this->timers[0][0];
                }
                while (count($this->timers) > 0) {
                    $timer = array_shift($this->timers);
                    if ($timer[0] <= microtime(true)) {
                        try {
                            //print_r($timer[1]);
                            call_user_func($timer[1]);
                        }
                        catch (Exception $e) {
                            fwrite(STDERR, "$e\r\n");
                        }
                    }
                    else {
                        array_unshift($this->timers, $timer); // put back for later
                        break;
                    }
                    $now = microtime(true);
                }
            }
            else {
                $is_first = false;
            }
        
            // Reset the sets of streams to select
            $read_streams = array_values($this->read_streams);
            $write_streams = array_values($this->write_streams);
            $except_streams = array_values($this->except_streams);
            $stream_count = count($read_streams) + count($write_streams) + count($except_streams);
            $tv_sec = null;
            $tv_usec = null;
            if (count($this->timers) > 0) {
                $time_until_next_timer = $this->timers[0][0] - microtime(true);
                if ($time_until_next_timer < 0) {
                    $tv_sec = $tv_usec = 0;
                }
                else {
                    $tv_sec = floor($time_until_next_timer);
                    $tv_usec = floor(($time_until_next_timer - $tv_sec) * 999999) + 1;
                }
            }
        }
    }
}

class IO {
    private $stream;
    private $events;
    private $data_in = array();
    private $data_in_count = 0;
    private $is_closed = false;
    
    public function __construct($stream, $events) {
        $this->stream = $stream;
        $this->events = $events;
    }
    
    public function flush() {
        if (false === fflush($this->stream)) {
            throw new Exception('failed flushing');
        }
    }
    
    public function close() {
        if (!$this->is_closed) {
            $this->is_closed = true;
            $this->events->close($this->stream);
        }
    }
    
    public function on_close($callback) {
        return $this->events->on_close($this->stream, $callback);
    }
    
    // Register a callback to call repeatedly whenever data is read.
    // May interact unexpectedly with read and readline if used concurrently.
    public function on_data_in($callback) {
        $data_in =& $this->data_in;
        $data_in_count =& $this->data_in_count;
        
        $this->events->on_read($this->stream, function ($is_first) use (&$callback) {
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
        $this->events->unregister_read($this->stream);
    }
    
    public function readline($callback, $delimiter = "\r\n") {
        $data_in =& $this->data_in;
        $data_in_count =& $this->data_in_count;
        $data = implode($data_in);
        
        if (false !== ($pos = strpos($data, $delimiter))) {
            $data_in = array(substr($data, $pos + strlen($delimiter)));
            $data_in_count = strlen($data_in[0]);
            call_user_func($callback, substr($data, 0, $pos));
        }
        
        $this->events->on_read($this->stream, function ($is_first) use ($callback, &$data_in, &$data_in_count, $delimiter) {
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
                $this->events->unregister_read($this->stream);
                $data_in = array(substr($data, $pos + strlen($delimiter)));
                $data_in_count = strlen($data_in[0]);
                call_user_func($callback, substr($data, 0, $pos));
            }
            else {
                $data_in = array($data);
            }
        });
    }
    
    public function read($length, $callback) {
        $data_in =& $this->data_in;
        $data_in_count =& $this->data_in_count;
        
        if ($length <= $data_in_count) {
            $data = implode($data_in);
            $data_in = array(substr($data, $length));
            $data_in_count = strlen($data_in[0]);
            call_user_func($callback, substr($data, 0, $length));
        }
        
        $this->events->on_read($this->stream, function ($is_first) use ($length, $callback, &$data_in, &$data_in_count) {
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
                $this->events->unregister_read($this->stream);
                
                $data = implode($data_in);
                $data_in = array(substr($data, $length));
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
        
        $this->events->on_write($this->stream, function () use (&$data, $callback, &$is_closed) {
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
                    $this->events->unregister_write($this->stream);
                    if (isset($callback)) {
                        call_user_func($callback);
                    }
                }
            }
        });
    }
}