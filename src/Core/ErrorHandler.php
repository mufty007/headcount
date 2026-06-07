<?php

namespace Headcount\Core;

/**
 * Error Handler Class
 * Handles exceptions and errors gracefully
 */
class ErrorHandler
{
    private static $config;

    /**
     * Initialize error handler
     */
    public static function init($config)
    {
        self::$config = $config;

        // Set error handler
        set_error_handler([self::class, 'handleError']);

        // Set exception handler
        set_exception_handler([self::class, 'handleException']);

        // Set error reporting based on environment
        if (($config['app']['debug'] ?? false) && ($config['app']['environment'] ?? 'production') === 'development') {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        } else {
            error_reporting(E_ALL);
            ini_set('display_errors', 0);
        }
    }

    /**
     * Handle PHP errors
     */
    public static function handleError($errno, $errstr, $errfile, $errline)
    {
        // Log error
        Logger::logError("Error [$errno]: $errstr in $errfile on line $errline");

        // Don't execute PHP internal error handler
        return true;
    }

    /**
     * Handle exceptions
     */
    public static function handleException($exception)
    {
        // Log exception
        Logger::logError("Uncaught exception: " . $exception->getMessage(), $exception);

        // Return user-friendly error response
        $isApiRequest = self::isApiRequest();

        if ($isApiRequest) {
            self::sendJsonError($exception);
        } else {
            self::sendHtmlError($exception);
        }
    }

    /**
     * Check if request is API request
     */
    private static function isApiRequest()
    {
        return strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false ||
               isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    }

    /**
     * Send JSON error response
     */
    private static function sendJsonError($exception)
    {
        http_response_code(500);
        header('Content-Type: application/json');

        $response = [
            'success' => false,
            'data' => null,
            'message' => 'An error occurred. Please try again.',
            'errors' => []
        ];

        // Include error details in development mode
        if (self::$config['app']['debug'] ?? false) {
            $response['debug'] = [
                'message' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];
        }

        echo json_encode($response);
        exit;
    }

    /**
     * Send HTML error response
     */
    private static function sendHtmlError($exception)
    {
        http_response_code(500);
        header('Content-Type: text/html');

        $debug = self::$config['app']['debug'] ?? false;

        echo '<!DOCTYPE html>
<html>
<head>
    <title>Error</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 40px; text-align: center; }
        .error { background: #fee; border: 1px solid #fcc; padding: 20px; border-radius: 5px; max-width: 600px; margin: 0 auto; }
        .debug { margin-top: 20px; text-align: left; background: #f5f5f5; padding: 15px; border-radius: 5px; font-family: monospace; font-size: 12px; }
    </style>
</head>
<body>
    <div class="error">
        <h1>An Error Occurred</h1>
        <p>We apologize for the inconvenience. Please try again later.</p>';

        if ($debug) {
            echo '<div class="debug">
                <strong>Error:</strong> ' . htmlspecialchars($exception->getMessage()) . '<br>
                <strong>File:</strong> ' . htmlspecialchars($exception->getFile()) . '<br>
                <strong>Line:</strong> ' . $exception->getLine() . '
            </div>';
        }

        echo '</div>
</body>
</html>';
        exit;
    }

    /**
     * Format validation errors for API response
     */
    public static function formatValidationErrors($errors)
    {
        return [
            'success' => false,
            'data' => null,
            'message' => 'Validation failed',
            'errors' => $errors
        ];
    }
}
