<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Services\EmailService;

/**
 * Email Verification Service
 * Sends and verifies portal registration email confirmation links
 */
class EmailVerificationService
{
    private $db;
    private $emailService;
    private $tokenExpiryHours = 48;

    public function __construct()
    {
        $this->db = Database::getInstance();

        $configFile = __DIR__ . '/../../config/config.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            if (!empty($config['smtp2go']['api_key']) && !empty(trim((string) ($config['smtp2go']['from_email'] ?? '')))) {
                $this->emailService = new EmailService($config['smtp2go']);
            }
        }
    }

    /**
     * Create a verification token and email it to the user.
     *
     * @return array{success:bool,message:string}
     */
    public function sendVerification(int $userId): array
    {
        $user = $this->db->queryOne(
            "SELECT id, email, first_name, organization_id, email_verified_at, status
             FROM users WHERE id = :id LIMIT 1",
            ['id' => $userId]
        );

        if (!$user || ($user['status'] ?? '') === 'deleted') {
            return [
                'success' => false,
                'message' => 'User not found'
            ];
        }

        if (!empty($user['email_verified_at'])) {
            return [
                'success' => true,
                'message' => 'Email is already verified'
            ];
        }

        if (empty($user['email'])) {
            return [
                'success' => false,
                'message' => 'No email address on file'
            ];
        }

        // Invalidate previous unused tokens
        try {
            $this->db->execute(
                "UPDATE email_verification_tokens SET used_at = NOW()
                 WHERE user_id = :uid AND used_at IS NULL",
                ['uid' => $userId]
            );
        } catch (\Exception $e) {
            error_log('EmailVerificationService: failed to invalidate old tokens: ' . $e->getMessage());
        }

        $token = Security::generateToken(64);
        $expiresAt = date('Y-m-d H:i:s', time() + ($this->tokenExpiryHours * 3600));

        $this->db->insert('email_verification_tokens', [
            'user_id' => $userId,
            'token' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ]);

        $baseUrl = $this->getBaseUrl();
        $verifyUrl = $baseUrl . '/portal/verify-email.php?token=' . urlencode($token);

        if ($this->emailService) {
            try {
                $this->sendVerificationEmail($user, $verifyUrl, (int) $user['organization_id']);
            } catch (\Exception $e) {
                error_log('EmailVerificationService: send failed: ' . $e->getMessage());
            }
        } else {
            error_log('EmailVerificationService: SMTP not configured - verification email was not sent.');
        }

        return [
            'success' => true,
            'message' => 'Verification email sent'
        ];
    }

    /**
     * Resend verification by email (enumeration-safe).
     */
    public function resendByEmail(string $email): array
    {
        $email = strtolower(trim($email));
        $generic = [
            'success' => true,
            'message' => 'If that email needs verification, a new link has been sent.'
        ];

        if ($email === '') {
            return $generic;
        }

        $user = $this->db->queryOne(
            "SELECT id, email_verified_at FROM users
             WHERE email = :email AND role = 'member' AND status = 'active'
             LIMIT 1",
            ['email' => $email]
        );

        if (!$user || !empty($user['email_verified_at'])) {
            return $generic;
        }

        $this->sendVerification((int) $user['id']);
        return $generic;
    }

    /**
     * Verify token and mark user email as verified.
     * Sends welcome email on first successful verification.
     *
     * @return array{success:bool,message:string,user?:array}
     */
    public function verifyToken(string $token): array
    {
        if ($token === '') {
            return [
                'success' => false,
                'message' => 'Invalid or expired verification link'
            ];
        }

        $hashedToken = hash('sha256', $token);

        $row = $this->db->queryOne(
            "SELECT t.id AS token_row_id, t.user_id, u.email, u.first_name, u.last_name,
                    u.organization_id, u.email_verified_at
             FROM email_verification_tokens t
             JOIN users u ON t.user_id = u.id
             WHERE t.token = :token
               AND t.used_at IS NULL
               AND t.expires_at > NOW()
               AND u.status = 'active'
             LIMIT 1",
            ['token' => $hashedToken]
        );

        if (!$row) {
            return [
                'success' => false,
                'message' => 'Invalid or expired verification link'
            ];
        }

        $alreadyVerified = !empty($row['email_verified_at']);

        $this->db->update('email_verification_tokens', $row['token_row_id'], [
            'used_at' => date('Y-m-d H:i:s')
        ]);

        if (!$alreadyVerified) {
            $this->db->execute(
                "UPDATE users SET email_verified_at = NOW() WHERE id = :id",
                ['id' => $row['user_id']]
            );

            try {
                $this->sendWelcomeEmail([
                    'id' => $row['user_id'],
                    'email' => $row['email'],
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                ], (int) $row['organization_id']);
            } catch (\Exception $e) {
                error_log('EmailVerificationService: welcome email failed: ' . $e->getMessage());
            }
        }

        return [
            'success' => true,
            'message' => 'Email verified successfully. You can now log in.',
            'user' => [
                'id' => $row['user_id'],
                'email' => $row['email'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
            ]
        ];
    }

    private function sendVerificationEmail(array $user, string $url, int $organizationId): void
    {
        if (!$this->emailService) {
            return;
        }

        $templatePath = __DIR__ . '/../../templates/portal/verify-email.html';
        $firstName = htmlspecialchars($user['first_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $hours = $this->tokenExpiryHours;

        if (file_exists($templatePath)) {
            $body = file_get_contents($templatePath);
            $body = str_replace('{first_name}', $user['first_name'] ?? '', $body);
            $body = str_replace('{verify_email_url}', $url, $body);
            $body = str_replace('{expiry_hours}', (string) $hours, $body);
        } else {
            $body = "
                <h2>Verify your email</h2>
                <p>Hi {$firstName},</p>
                <p>Please confirm your email address to activate your account.</p>
                <p><a href=\"{$url}\" style=\"display:inline-block;padding:10px 20px;background:#4F46E5;color:white;text-decoration:none;border-radius:5px;\">Verify Email</a></p>
                <p>This link expires in {$hours} hours.</p>
                <p>If you did not create an account, you can ignore this email.</p>
            ";
        }

        $this->emailService->sendEmail(
            $user['email'],
            'Verify your email address',
            $body,
            $organizationId,
            [
                'email_type' => 'email_verification',
                'user_id' => $user['id'] ?? null,
            ]
        );
    }

    private function sendWelcomeEmail(array $user, int $organizationId): void
    {
        if (!$this->emailService) {
            $configFile = __DIR__ . '/../../config/config.php';
            if (file_exists($configFile)) {
                $config = require $configFile;
                if (!empty($config['smtp2go']['api_key'])) {
                    $this->emailService = new EmailService($config['smtp2go']);
                }
            }
        }
        if (!$this->emailService) {
            return;
        }

        $templatePath = __DIR__ . '/../../templates/portal/welcome.html';
        $memberName = trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));

        if (file_exists($templatePath)) {
            $body = file_get_contents($templatePath);
            $body = str_replace('{first_name}', $user['first_name'] ?? '', $body);
            $body = str_replace('{full_name}', $memberName, $body);
            $body = str_replace('{email}', $user['email'] ?? '', $body);
        } else {
            $body = "
                <h2>Welcome, {$user['first_name']}!</h2>
                <p>Thank you for registering. Your email has been verified and your account is ready.</p>
            ";
        }

        $this->emailService->sendEmail(
            $user['email'],
            'Welcome! Your Account Has Been Created',
            $body,
            $organizationId,
            [
                'email_type' => 'welcome',
                'user_id' => $user['id'] ?? null,
            ]
        );
    }

    private function getBaseUrl(): string
    {
        $configFile = __DIR__ . '/../../config/config.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            return headcount_portal_base_url($config);
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocol . '://' . $host;
    }
}
