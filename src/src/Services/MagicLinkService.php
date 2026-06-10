<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Services\EmailService;

/**
 * Magic Link Service
 * Handles passwordless authentication via magic links
 */
class MagicLinkService
{
    private $db;
    private $emailService;
    private $tokenExpiryMinutes = 15;

    public function __construct()
    {
        $this->db = Database::getInstance();
        
        // Ensure magic_link_tokens table exists
        $this->ensureMagicLinkTokensTable();
        
        // Initialize email service if config exists
        $configFile = __DIR__ . '/../../config/config.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            if (!empty($config['smtp2go']['api_key']) && !empty(trim((string) ($config['smtp2go']['from_email'] ?? '')))) {
                $this->emailService = new EmailService($config['smtp2go']);
            }
        }
    }

    /**
     * Ensure magic_link_tokens table exists
     */
    private function ensureMagicLinkTokensTable()
    {
        try {
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'magic_link_tokens'");
            if (!empty($tableCheck)) {
                // Table exists
                return true;
            }
            
            // Table doesn't exist, create it
            $pdo = $this->db->getConnection();
            $pdo->exec("CREATE TABLE IF NOT EXISTS `magic_link_tokens` (
              `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT UNSIGNED NOT NULL,
              `token` VARCHAR(255) NOT NULL,
              `expires_at` TIMESTAMP NOT NULL,
              `used_at` TIMESTAMP NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
              INDEX `idx_token` (`token`),
              INDEX `idx_user` (`user_id`),
              INDEX `idx_expires` (`expires_at`),
              INDEX `idx_used` (`used_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            
            return true;
        } catch (\Exception $e) {
            error_log("MagicLinkService - Error creating magic_link_tokens table: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Generate and send magic link
     * 
     * @param string $email User email address
     * @param int|null $organizationId Organization ID (optional, will be looked up)
     * @return array Result with success status and message
     */
    public function generateAndSend($email, $organizationId = null)
    {
        // Find user by email
        $sql = "SELECT u.*, o.id as org_id FROM users u 
                LEFT JOIN organizations o ON u.organization_id = o.id 
                WHERE u.email = :email AND u.role = 'member' AND u.status = 'active' 
                LIMIT 1";
        $user = $this->db->queryOne($sql, ['email' => $email]);

        if (!$user) {
            // Don't reveal if user exists (security best practice)
            return [
                'success' => true,
                'message' => 'If that email exists, a magic link has been sent.'
            ];
        }

        // Use provided organizationId or user's organization
        $orgId = $organizationId ?? $user['org_id'] ?? $user['organization_id'];

        // Generate secure token
        $token = Security::generateToken(64);
        $expiresAt = date('Y-m-d H:i:s', time() + ($this->tokenExpiryMinutes * 60));

        // Store token in database
        try {
            $this->db->insert('magic_link_tokens', [
                'user_id' => $user['id'],
                'token' => hash('sha256', $token), // Store hashed version
                'expires_at' => $expiresAt
            ]);
        } catch (\Exception $e) {
            error_log("MagicLinkService - Failed to insert magic link token: " . $e->getMessage());
            // Try to ensure table exists again (in case it was deleted)
            $this->ensureMagicLinkTokensTable();
            // Retry insert
            try {
                $this->db->insert('magic_link_tokens', [
                    'user_id' => $user['id'],
                    'token' => hash('sha256', $token),
                    'expires_at' => $expiresAt
                ]);
            } catch (\Exception $e2) {
                error_log("MagicLinkService - Retry insert also failed: " . $e2->getMessage());
                return [
                    'success' => false,
                    'message' => 'Failed to generate magic link. Please try again or contact support.'
                ];
            }
        }

        // Generate magic link URL (use verify.php so the link works regardless of router config)
        $baseUrl = $this->getBaseUrl();
        $magicLinkUrl = $baseUrl . '/portal/verify.php?token=' . urlencode($token);

        // Send email if email service is available
        if ($this->emailService) {
            try {
                $this->sendMagicLinkEmail($email, $token, $magicLinkUrl, $orgId);
            } catch (\Exception $e) {
                error_log("Failed to send magic link email: " . $e->getMessage());
                // Continue even if email fails - token is still valid
            }
        } else {
            error_log("MagicLinkService: SMTP not configured - magic link email was not sent. Set smtp2go in config.");
        }

        return [
            'success' => true,
            'message' => 'Magic link sent to your email',
            'token' => $token // Only for testing - remove in production
        ];
    }

    /**
     * Verify magic link token and return user data
     * 
     * @param string $token Plain text token
     * @return array|null User data if valid, null if invalid
     */
    public function verifyToken($token)
    {
        $hashedToken = hash('sha256', $token);

        // Find valid, unused token (alias t.id so it is not overwritten by u.id)
        $sql = "SELECT t.id AS token_row_id, t.user_id, u.email, u.first_name, u.last_name, u.organization_id, u.role
                FROM magic_link_tokens t
                JOIN users u ON t.user_id = u.id
                WHERE t.token = :token 
                AND t.used_at IS NULL 
                AND t.expires_at > NOW()
                AND u.status = 'active'
                LIMIT 1";
        
        $result = $this->db->queryOne($sql, ['token' => $hashedToken]);

        if (!$result) {
            return null;
        }

        // Mark token as used (use token row id, not user id)
        $this->db->update('magic_link_tokens', $result['token_row_id'], [
            'used_at' => date('Y-m-d H:i:s')
        ]);

        // Return user data (excluding sensitive fields)
        return [
            'id' => $result['user_id'],
            'email' => $result['email'],
            'first_name' => $result['first_name'],
            'last_name' => $result['last_name'],
            'organization_id' => $result['organization_id'],
            'role' => $result['role']
        ];
    }

    /**
     * Send magic link email
     */
    private function sendMagicLinkEmail($email, $token, $url, $organizationId)
    {
        $templatePath = __DIR__ . '/../../templates/portal/magic-link.html';
        
        if (file_exists($templatePath)) {
            $body = file_get_contents($templatePath);
            $body = str_replace('{magic_link_url}', $url, $body);
            $body = str_replace('{expiry_minutes}', $this->tokenExpiryMinutes, $body);
        } else {
            // Fallback template
            $body = "
                <h2>Your Magic Link</h2>
                <p>Click the link below to log in to your account:</p>
                <p><a href=\"{$url}\" style=\"display:inline-block;padding:10px 20px;background:#3B82F6;color:white;text-decoration:none;border-radius:5px;\">Log In</a></p>
                <p>This link will expire in {$this->tokenExpiryMinutes} minutes.</p>
                <p>If you didn't request this link, please ignore this email.</p>
            ";
        }

        $subject = "Your Magic Link Login";

        $this->emailService->sendEmail(
            $email,
            $subject,
            $body,
            $organizationId,
            [
                'email_type' => 'magic_link',
                'user_id' => null // Will be set after user is identified
            ]
        );
    }

    /**
     * Get base URL for magic links
     * Uses config app.url when set so links are correct when request is from API
     */
    private function getBaseUrl()
    {
        $configFile = __DIR__ . '/../../config/config.php';
        if (file_exists($configFile)) {
            $config = require $configFile;

            return headcount_portal_base_url($config);
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $basePath = dirname($scriptName);
        $basePath = rtrim($basePath, '/');

        return $protocol . '://' . $host . $basePath;
    }

    /**
     * Clean up expired tokens (should be run via cron)
     */
    public function cleanupExpiredTokens()
    {
        $sql = "DELETE FROM magic_link_tokens 
                WHERE expires_at < NOW() OR used_at IS NOT NULL";
        $this->db->execute($sql);
    }
}
