<?php

// Disable phpcs for this file
// phpcs:disable

// Load Composer autoloader
require_once __DIR__ . '/../../vendor/autoload.php';

// Create mock Redis class if it doesn't exist
if (!class_exists('Redis')) {
    class Redis
    {
        public function connect($host, $port, $timeout = 0.0, $reserved = null, $retryInterval = 0, $readTimeout = 0.0)
        {
            return true;
        }
        
        public function auth($password)
        {
            return true;
        }
        
        public function select($dbIndex)
        {
            return true;
        }
        
        public function get($key)
        {
            return null;
        }
        
        public function set($key, $value, $timeout = 0)
        {
            return true;
        }
        
        public function del($key)
        {
            return true;
        }
        
        public function zadd($key, $score, $value)
        {
            return true;
        }
        
        public function zrem($key, $member)
        {
            return true;
        }
        
        public function zrange($key, $start, $end, $withscores = false)
        {
            return [];
        }
        
        public function zremrangebyscore($key, $min, $max)
        {
            return 0;
        }
        
        public function zcard($key)
        {
            return 0;
        }
        
        public function expire($key, $ttl)
        {
            return true;
        }
        
        public function ttl($key)
        {
            return -1;
        }
        
        public function incr($key)
        {
            return 1;
        }
        
        public function ping()
        {
            return '+PONG';
        }
    }
}

// Create mock Memcached class if it doesn't exist
if (!class_exists('Memcached')) {
    class Memcached
    {
        public function addServer($host, $port, $weight = 0)
        {
            return true;
        }
        
        public function get($key)
        {
            return null;
        }
        
        public function set($key, $value, $expiration = 0)
        {
            return true;
        }
        
        public function delete($key)
        {
            return true;
        }
        
        public function flush()
        {
            return true;
        }
        
        public function increment($key, $offset = 1)
        {
            return 1;
        }
    }
}

// Register PSR-4 autoloader for test classes
spl_autoload_register(function ($class) {
    $prefix = 'Glueful\\Tests\\';
    $base_dir = __DIR__ . '/../../tests/';
    
    // Does the class use the namespace prefix?
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        // No, move to the next registered autoloader
        return;
    }
    
    // Get the relative class name
    $relative_class = substr($class, $len);
    
    // Replace namespace separators with directory separators and append '.php'
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';
    
    // If the file exists, require it
    if (file_exists($file)) {
        require $file;
    }
});
