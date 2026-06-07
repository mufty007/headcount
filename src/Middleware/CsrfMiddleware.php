<?php

namespace Headcount\Middleware;

use Headcount\Helpers\Security;
use Headcount\Helpers\Utilities;

/**
 * CSRF Protection Middleware
 */
class CsrfMiddleware
{
    /**
     * Verify CSRF token for POST requests
     * 
     * @param array|null $inputData Optional pre-parsed JSON input data (to avoid reading php://input twice)
     */
    public static function verify($inputData = null)
    {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Try multiple sources for CSRF token:
            // 1. POST data (form submissions)
            // 2. HTTP header (X-CSRF-Token - PHP converts to HTTP_X_CSRF_TOKEN)
            // 3. JSON body (for API requests) - passed as parameter or read from input
            $token = $_POST['csrf_token'] ?? null;
            
            // Check headers (PHP converts headers: X-CSRF-Token -> HTTP_X_CSRF_TOKEN)
            if (!$token) {
                // Try standard PHP header conversion
                $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
            }
            
            if (!$token) {
                // Try alternative header names that some servers use
                $token = $_SERVER['X_CSRF_TOKEN'] ?? $_SERVER['REDIRECT_HTTP_X_CSRF_TOKEN'] ?? null;
            }

            // Pre-parsed JSON body (router and portal endpoints pass this; do not depend on REQUEST_URI)
            if (!$token && is_array($inputData) && isset($inputData['csrf_token'])) {
                $token = $inputData['csrf_token'];
            }

            // Fallback: read JSON from php://input for API-style routes when body was not passed in
            if (!$token && self::isApiRequest()) {
                $input = @json_decode(file_get_contents('php://input'), true);
                if (is_array($input) && isset($input['csrf_token'])) {
                    $token = $input['csrf_token'];
                }
            }

            if (is_string($token)) {
                $token = trim($token);
            } elseif ($token !== null && is_scalar($token)) {
                $token = trim((string) $token);
            } else {
                $token = null;
            }

            if (!$token || !Security::verifyCSRFToken($token)) {
                if (self::isApiRequest()) {
                    Utilities::jsonResponse(false, null, 'Invalid CSRF token. Please refresh the page and try again.', [], 403);
                } else {
                    http_response_code(403);
                    die('Invalid security token. Please refresh the page and try again.');
                }
                exit;
            }
        }

        return true;
    }

    /**
     * Generate and return CSRF token
     */
    public static function getToken()
    {
        return Security::generateCSRFToken();
    }

    /**
     * Check if request is API request
     */
    private static function isApiRequest()
    {
        return strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
    }
}
