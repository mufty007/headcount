<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Helpers\Validator;
use Headcount\Core\RateLimiter;

/**
 * Member Registration Service
 * Handles member self-registration
 */
class MemberRegistrationService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Register a new member
     * 
     * @param array $data Registration data
     * @return array Result with success status and member data
     */
    public function register($data)
    {
        $errors = [];

        // Validate required fields
        if (empty($data['first_name'])) {
            $errors[] = 'First name is required';
        }

        if (empty($data['last_name'])) {
            $errors[] = 'Last name is required';
        }

        $email = isset($data['email']) ? trim((string) $data['email']) : '';
        $emailConfirm = isset($data['email_confirm']) ? trim((string) $data['email_confirm']) : '';

        if ($email === '') {
            $errors[] = 'Email is required';
        } elseif (!Validator::email($email)) {
            $errors[] = 'Please enter a valid email address';
        } elseif ($emailConfirm === '') {
            $errors[] = 'Please confirm your email address';
        } elseif (strtolower($email) !== strtolower($emailConfirm)) {
            $errors[] = 'Email addresses do not match';
        } elseif (Validator::isDisposableEmail($email)) {
            $errors[] = 'Please use a permanent email address';
        } elseif (!Validator::emailDomainAcceptsMail($email)) {
            $errors[] = 'Please enter a valid email address';
        }

        if (empty($data['organization_id'])) {
            $errors[] = 'Organization ID is required';
        }

        // Check for duplicate email
        if (empty($errors) && $email !== '') {
            $existing = $this->db->queryOne(
                "SELECT id FROM users WHERE email = :email AND organization_id = :org_id AND status != 'deleted'",
                ['email' => strtolower($email), 'org_id' => $data['organization_id']]
            );
            
            if ($existing) {
                $errors[] = 'A member with this email already exists';
            }
        }

        // Phone is required (numbers are NOT unique — family members may share one)
        $phone = isset($data['phone']) ? trim((string) $data['phone']) : '';
        if ($phone === '') {
            $errors[] = 'Phone number is required';
        } elseif (!Validator::phone($phone)) {
            $errors[] = 'Please enter a valid phone number';
        }

        // Password is required for member registration
        if (empty($data['password'])) {
            $errors[] = 'Password is required';
        } else {
            $passwordErrors = Security::validatePassword($data['password']);
            if (!empty($passwordErrors)) {
                $errors = array_merge($errors, $passwordErrors);
            }
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'errors' => $errors
            ];
        }

        $normalizedEmail = strtolower($email);

        try {
            RateLimiter::checkVerificationEmailRateLimit($normalizedEmail);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'errors' => [$e->getMessage()]
            ];
        }

        // Prepare user data — email_verified_at left null until they confirm
        $userData = [
            'organization_id' => $data['organization_id'],
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'email' => $normalizedEmail,
            'phone' => $phone,
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => !empty($data['date_of_birth']) ? $data['date_of_birth'] : null,
            'role' => 'member',
            'status' => 'active',
            'qr_code_secret' => Security::generateToken(32)
        ];

        if (!empty($data['password'])) {
            $userData['password_hash'] = Security::hashPassword($data['password']);
        }

        $userData['email_preferences'] = json_encode([
            'event_announcements' => true,
            'event_reminders' => true,
            'rsvp_confirmations' => true,
            'payment_receipts' => true
        ]);

        $userData['communication_preferences'] = json_encode([
            'email_enabled' => true,
            'sms_enabled' => false
        ]);

        try {
            $userId = $this->db->insert('users', $userData);

            $user = $this->db->queryOne(
                "SELECT * FROM users WHERE id = :id",
                ['id' => $userId]
            );

            $verificationService = new EmailVerificationService();
            try {
                $verificationService->sendVerification((int) $userId);
            } catch (\Exception $e) {
                error_log("Failed to send verification email: " . $e->getMessage());
            }

            return [
                'success' => true,
                'message' => 'Registration successful. Please check your email to verify your account.',
                'requires_verification' => true,
                'user' => [
                    'id' => $user['id'],
                    'email' => $user['email'],
                    'first_name' => $user['first_name'],
                    'last_name' => $user['last_name']
                ]
            ];

        } catch (\Exception $e) {
            error_log("Registration error: " . $e->getMessage());
            return [
                'success' => false,
                'errors' => ['Registration failed. Please try again.']
            ];
        }
    }
}
