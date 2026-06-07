<?php

namespace Headcount\Middleware;

/**
 * Admin Middleware
 * Checks if user has admin role
 */
class AdminMiddleware
{
    public function handle(): bool
    {
        if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
            if ($this->isApiRequest()) {
                http_response_code(403);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'message' => 'Admin access required',
                    'errors' => []
                ]);
                exit;
            } else {
                header('Location: /admin/login');
                exit;
            }
        }

        return true;
    }

    /**
     * Check if request is API request
     */
    private function isApiRequest(): bool
    {
        return strpos($_SERVER['REQUEST_URI'], '/api/') !== false;
    }
}
