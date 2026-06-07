<?php

namespace Headcount\Core;

/**
 * Security Logger Class
 * Handles security-specific event logging
 */
class SecurityLogger
{
    private static $logPath;

    /**
     * Initialize logger
     */
    public static function init($logPath = null)
    {
        try {
            self::$logPath = $logPath ?? __DIR__ . '/../../logs';
            
            // Create log directory if it doesn't exist
            if (!is_dir(self::$logPath)) {
                @mkdir(self::$logPath, 0755, true);
                // If still doesn't exist, use system temp directory as fallback
                if (!is_dir(self::$logPath)) {
                    self::$logPath = sys_get_temp_dir();
                }
            }
        } catch (\Exception $e) {
            // If initialization fails, use system temp directory
            error_log("SecurityLogger init failed: " . $e->getMessage());
            self::$logPath = sys_get_temp_dir();
        }
    }

    /**
     * Log security event
     */
    public static function log($event, $details = [])
    {
        if (!self::$logPath) {
            self::init();
        }

        $log = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'user_id' => $_SESSION['user_id'] ?? null,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
            'details' => $details
        ];
        
        try {
            $logFile = self::$logPath . '/security.log';
            $logMessage = json_encode($log) . "\n";
            
            @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            // Silently fail - don't break the application if logging fails
            error_log("SecurityLogger::log() failed: " . $e->getMessage());
        }
    }
    
    /**
     * Log failed login attempt
     */
    public static function logFailedLogin($email)
    {
        self::log('failed_login', ['email' => $email]);
    }
    
    /**
     * Log successful login
     */
    public static function logSuccessfulLogin($userId, $email = null)
    {
        self::log('successful_login', [
            'user_id' => $userId,
            'email' => $email
        ]);
    }
    
    /**
     * Log unauthorized access attempt
     */
    public static function logUnauthorizedAccess($resource)
    {
        self::log('unauthorized_access', ['resource' => $resource]);
    }
    
    /**
     * Log data access
     */
    public static function logDataAccess($entityType, $entityId)
    {
        self::log('data_access', [
            'entity_type' => $entityType,
            'entity_id' => $entityId
        ]);
    }
    
    /**
     * Log data modification
     */
    public static function logDataModification($entityType, $entityId, $action)
    {
        self::log('data_modification', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action
        ]);
    }
    
    /**
     * Log file upload attempt
     */
    public static function logFileUpload($filename, $success, $reason = null)
    {
        self::log('file_upload', [
            'filename' => $filename,
            'success' => $success,
            'reason' => $reason
        ]);
    }
    
    /**
     * Log rate limit violation
     */
    public static function logRateLimitViolation($type, $identifier)
    {
        self::log('rate_limit_violation', [
            'type' => $type,
            'identifier' => $identifier
        ]);
    }
    
    /**
     * Log payment anomaly
     */
    public static function logPaymentAnomaly($paymentId, $details)
    {
        self::log('payment_anomaly', [
            'payment_id' => $paymentId,
            'details' => $details
        ]);
    }
}
