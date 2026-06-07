<?php

namespace Headcount\Helpers;

/**
 * Logger Helper Class
 * Provides logging functionality
 */
class Logger
{
    private $logPath;
    private $enabled;

    public function __construct(string $logPath, bool $enabled = true)
    {
        $this->logPath = $logPath;
        $this->enabled = $enabled;

        // Create log directory if it doesn't exist
        if ($enabled && !is_dir($logPath)) {
            mkdir($logPath, 0755, true);
        }
    }

    /**
     * Log a message
     */
    public function log(string $message, string $level = 'INFO'): void
    {
        if (!$this->enabled) {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}\n";
        
        $logFile = $this->logPath . '/app.log';
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }

    /**
     * Log an error
     */
    public function error(string $message, ?\Throwable $exception = null): void
    {
        $this->log($message, 'ERROR');
        
        if ($exception) {
            $this->log('Exception: ' . $exception->getMessage(), 'ERROR');
            $this->log('Stack trace: ' . $exception->getTraceAsString(), 'ERROR');
        }
    }

    /**
     * Log a warning
     */
    public function warning(string $message): void
    {
        $this->log($message, 'WARNING');
    }

    /**
     * Log info message
     */
    public function info(string $message): void
    {
        $this->log($message, 'INFO');
    }

    /**
     * Log debug message
     */
    public function debug(string $message): void
    {
        $this->log($message, 'DEBUG');
    }

    /**
     * Log check-in event
     */
    public function logCheckIn(int $eventId, int $userId, ?int $adminId = null): void
    {
        $adminInfo = $adminId ? " by admin {$adminId}" : '';
        $this->info("Check-in: Event {$eventId}, User {$userId}{$adminInfo}");
    }

    /**
     * Log payment event
     */
    public function logPayment(int $eventId, int $userId, float $amount, string $status = 'success'): void
    {
        $this->info("Payment: Event {$eventId}, User {$userId}, Amount \${$amount}, Status: {$status}");
    }

    /**
     * Log email send
     */
    public function logEmail(string $to, string $subject, bool $success, ?string $error = null): void
    {
        $status = $success ? 'sent' : 'failed';
        $message = "Email {$status}: To {$to}, Subject: {$subject}";
        if ($error) {
            $message .= ", Error: {$error}";
        }
        $this->log($message, $success ? 'INFO' : 'ERROR');
    }

    /**
     * Log access attempt
     */
    public function logAccess(string $action, bool $success, ?string $reason = null): void
    {
        $status = $success ? 'allowed' : 'denied';
        $message = "Access {$status}: {$action}";
        if ($reason) {
            $message .= ", Reason: {$reason}";
        }
        $this->log($message, $success ? 'INFO' : 'WARNING');
    }
}
