<?php

namespace Headcount\Core;

/**
 * Rate Limiter Class
 * Handles rate limiting for login attempts and API requests
 */
class RateLimiter
{
    /**
     * Check login attempts and enforce lockout
     */
    public static function checkLoginAttempts($email)
    {
        $key = 'login_attempts_' . md5($email);
        $attempts = $_SESSION[$key] ?? 0;
        
        if ($attempts >= 5) {
            $lastAttempt = $_SESSION[$key . '_time'] ?? 0;
            $lockoutTime = 1800; // 30 minutes
            
            if (time() - $lastAttempt < $lockoutTime) {
                $remaining = $lockoutTime - (time() - $lastAttempt);
                throw new \Exception("Too many login attempts. Try again in " . ceil($remaining / 60) . " minutes.");
            } else {
                // Reset after lockout period
                unset($_SESSION[$key]);
                unset($_SESSION[$key . '_time']);
            }
        }
        
        return true;
    }
    
    /**
     * Record failed login attempt
     */
    public static function recordFailedLogin($email)
    {
        $key = 'login_attempts_' . md5($email);
        $_SESSION[$key] = ($_SESSION[$key] ?? 0) + 1;
        $_SESSION[$key . '_time'] = time();
    }
    
    /**
     * Reset login attempts after successful login
     */
    public static function resetLoginAttempts($email)
    {
        $key = 'login_attempts_' . md5($email);
        unset($_SESSION[$key]);
        unset($_SESSION[$key . '_time']);
    }
    
    /**
     * Check API rate limit
     */
    public static function checkApiRateLimit($ip, $limit = 60, $window = 60)
    {
        $key = 'api_requests_' . md5($ip);
        $requests = $_SESSION[$key] ?? [];
        
        // Remove old requests outside window
        $now = time();
        $requests = array_filter($requests, function($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        });
        
        if (count($requests) >= $limit) {
            http_response_code(429);
            header('Retry-After: ' . $window);
            die(json_encode([
                'success' => false,
                'message' => 'Rate limit exceeded. Please try again later.',
                'retry_after' => $window
            ]));
        }
        
        $requests[] = $now;
        $_SESSION[$key] = $requests;
        
        return true;
    }

    /**
     * Check per-user upload rate limit (file-based, survives session restarts).
     */
    public static function checkUploadRateLimit(int $userId, int $limit = 20, int $window = 3600): void
    {
        $cacheDir = dirname(__DIR__) . '/../cache/rate_limits';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $file = $cacheDir . '/upload_' . $userId . '.json';
        $now = time();
        $timestamps = [];

        if (is_readable($file)) {
            $decoded = json_decode((string) file_get_contents($file), true);
            if (is_array($decoded)) {
                $timestamps = array_values(array_filter($decoded, static function ($ts) use ($now, $window) {
                    return is_int($ts) && ($now - $ts) < $window;
                }));
            }
        }

        if (count($timestamps) >= $limit) {
            http_response_code(429);
            header('Retry-After: ' . $window);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Upload limit exceeded. Maximum ' . $limit . ' uploads per hour.',
                'retry_after' => $window,
            ]);
            exit;
        }

        $timestamps[] = $now;
        file_put_contents($file, json_encode($timestamps), LOCK_EX);
    }
}
