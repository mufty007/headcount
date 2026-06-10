<?php

namespace Headcount\Core;

/**
 * Cache Class
 * Simple file-based caching implementation
 * Can be extended to use Redis/Memcached in production
 */
class Cache
{
    private static $cacheDir = null;
    private static $defaultTTL = 300; // 5 minutes

    /**
     * Initialize cache directory
     */
    private static function init()
    {
        if (self::$cacheDir === null) {
            $cacheDir = __DIR__ . '/../../cache';
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }
            self::$cacheDir = $cacheDir;
        }
    }

    /**
     * Get cache file path
     */
    private static function getCachePath($key)
    {
        self::init();
        $safeKey = md5($key);
        return self::$cacheDir . '/' . $safeKey . '.cache';
    }

    /**
     * Get cached value
     * 
     * @param string $key Cache key
     * @return mixed|null Cached value or null if not found/expired
     */
    public static function get($key)
    {
        $cachePath = self::getCachePath($key);
        
        if (!file_exists($cachePath)) {
            return null;
        }

        $data = @unserialize(file_get_contents($cachePath));
        
        if ($data === false) {
            return null;
        }

        // Check expiration
        if (isset($data['expires']) && $data['expires'] < time()) {
            @unlink($cachePath);
            return null;
        }

        return $data['value'] ?? null;
    }

    /**
     * Set cached value
     * 
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int $ttl Time to live in seconds (default 5 minutes)
     * @return bool Success
     */
    public static function set($key, $value, $ttl = null)
    {
        if ($ttl === null) {
            $ttl = self::$defaultTTL;
        }

        $cachePath = self::getCachePath($key);
        
        $data = [
            'value' => $value,
            'expires' => time() + $ttl,
            'created' => time()
        ];

        return file_put_contents($cachePath, serialize($data)) !== false;
    }

    /**
     * Delete cached value
     * 
     * @param string $key Cache key
     * @return bool Success
     */
    public static function delete($key)
    {
        $cachePath = self::getCachePath($key);
        
        if (file_exists($cachePath)) {
            return @unlink($cachePath);
        }
        
        return true;
    }

    /**
     * Clear all cache
     * 
     * @return int Number of files deleted
     */
    public static function clear()
    {
        self::init();
        $count = 0;
        
        $files = glob(self::$cacheDir . '/*.cache');
        foreach ($files as $file) {
            if (@unlink($file)) {
                $count++;
            }
        }
        
        return $count;
    }

    /**
     * Check if key exists and is not expired
     * 
     * @param string $key Cache key
     * @return bool
     */
    public static function has($key)
    {
        return self::get($key) !== null;
    }

    /**
     * Get or set (if not exists)
     * 
     * @param string $key Cache key
     * @param callable $callback Callback to generate value if not cached
     * @param int $ttl Time to live in seconds
     * @return mixed Cached or generated value
     */
    public static function remember($key, $callback, $ttl = null)
    {
        $value = self::get($key);
        
        if ($value === null) {
            $value = call_user_func($callback);
            self::set($key, $value, $ttl);
        }
        
        return $value;
    }
}
