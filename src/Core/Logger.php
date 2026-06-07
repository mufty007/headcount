<?php

namespace Headcount\Core;

/**
 * Logger Class
 * Handles application logging
 */
class Logger
{
    private static $config;
    private static $logPath;

    /**
     * Initialize logger with config
     */
    public static function init($config)
    {
        self::$config = $config;
        self::$logPath = $config['log_path'] ?? __DIR__ . '/../../logs';

        // Create log directory if it doesn't exist
        if (!is_dir(self::$logPath)) {
            mkdir(self::$logPath, 0755, true);
        }
    }

    /**
     * Check if logging is enabled
     */
    private static function isEnabled()
    {
        if (!is_array(self::$config)) {
            return true;
        }
        return self::$config['enabled'] ?? true;
    }

    /**
     * Check if level should be logged
     */
    private static function shouldLog($level)
    {
        if (!self::isEnabled()) {
            return false;
        }

        $levels = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];
        $configLevel = (is_array(self::$config) && isset(self::$config['level']))
            ? self::$config['level']
            : 'INFO';
        $configLevelValue = $levels[$configLevel] ?? 1;
        $messageLevelValue = $levels[$level] ?? 1;

        return $messageLevelValue >= $configLevelValue;
    }

    /**
     * Write log message
     */
    public static function log($message, $level = 'INFO')
    {
        if (!self::shouldLog($level)) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message";

        if (self::$logPath === null || self::$logPath === '') {
            error_log($logMessage);
            return;
        }

        $logFile = rtrim(self::$logPath, '/\\') . DIRECTORY_SEPARATOR . 'app.log';
        if (@file_put_contents($logFile, $logMessage . "\n", FILE_APPEND | LOCK_EX) === false) {
            error_log($logMessage . ' (file log failed)');
        }
    }

    /**
     * Log error message
     */
    public static function logError($message, $exception = null)
    {
        self::log($message, 'ERROR');
        if ($exception) {
            self::log("Exception: " . $exception->getMessage(), 'ERROR');
            self::log("Stack trace: " . $exception->getTraceAsString(), 'ERROR');
        }
    }

    /**
     * Log debug message
     */
    public static function debug($message)
    {
        self::log($message, 'DEBUG');
    }

    /**
     * Log info message
     */
    public static function info($message)
    {
        self::log($message, 'INFO');
    }

    /**
     * Log warning message
     */
    public static function warning($message)
    {
        self::log($message, 'WARNING');
    }

    /**
     * Log error message
     */
    public static function error($message, $exception = null)
    {
        self::logError($message, $exception);
    }

    /**
     * Log check-in event
     */
    public static function logCheckIn($eventId, $userId, $adminId)
    {
        self::info("Check-in: Event $eventId, User $userId, Admin $adminId");
    }

    /**
     * Log payment event
     */
    public static function logPayment($eventId, $userId, $amount)
    {
        self::info("Payment: Event $eventId, User $userId, Amount $amount");
    }

    /**
     * Log login attempt
     */
    public static function logLogin($email, $success, $reason = null)
    {
        $status = $success ? 'SUCCESS' : 'FAILED';
        $message = "Login $status: $email";
        if ($reason) {
            $message .= " - $reason";
        }
        self::info($message);
    }
}
