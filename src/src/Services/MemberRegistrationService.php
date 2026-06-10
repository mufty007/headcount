<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Helpers\Validator;
use Headcount\Services\EmailService;

/**
 * Member Registration Service
 * Handles member self-registration
 */
class MemberRegistrationService
{
    private $db;
    private $emailService;

    public function __construct()
    {
        $this->db = Database::getInstance();
        
        // Initialize email service if config exists
        $configFile = __DIR__ . '/../../config/config.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            if (!empty($config['smtp2go']['api_key'])) {
                $this->emailService = new EmailService($config['smtp2go']);
            }
        }
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

        if (empty($data['email'])) {
            $errors[] = 'Email is required';
        } elseif (!Validator::email($data['email'])) {
            $errors[] = 'Please enter a valid email address';
        }

        if (empty($data['organization_id'])) {
            $errors[] = 'Organization ID is required';
        }

        // Check for duplicate email
        if (empty($errors) && !empty($data['email'])) {
            $existing = $this->db->queryOne(
                "SELECT id FROM users WHERE email = :email AND organization_id = :org_id AND status != 'deleted'",
                ['email' => $data['email'], 'org_id' => $data['organization_id']]
            );
            
            if ($existing) {
                $errors[] = 'A member with this email already exists';
            }
        }

        // Note: Phone numbers are NOT unique - multiple members can share the same phone (e.g., family members)

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

        // Prepare user data
        $userData = [
            'organization_id' => $data['organization_id'],
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'email' => trim(strtolower($data['email'])),
            'phone' => !empty($data['phone']) ? trim($data['phone']) : null,
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => !empty($data['date_of_birth']) ? $data['date_of_birth'] : null,
            'role' => 'member',
            'status' => 'active',
            'qr_code_secret' => Security::generateToken(32) // Generate QR code secret
        ];

        // Hash password if provided
        if (!empty($data['password'])) {
            $userData['password_hash'] = Security::hashPassword($data['password']);
        }

        // Set default preferences
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
            // Create user
            $userId = $this->db->insert('users', $userData);

            // Get created user
            $user = $this->db->queryOne(
                "SELECT * FROM users WHERE id = :id",
                ['id' => $userId]
            );

            // Send welcome email
            if ($this->emailService) {
                try {
                    $this->sendWelcomeEmail($user, $data['organization_id']);
                } catch (\Exception $e) {
                    error_log("Failed to send welcome email: " . $e->getMessage());
                    // Don't fail registration if email fails
                }
            }

            return [
                'success' => true,
                'message' => 'Registration successful',
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

    /**
     * Send welcome email to new member
     */
    private function sendWelcomeEmail($user, $organizationId)
    {
        $templatePath = __DIR__ . '/../../templates/portal/welcome.html';
        
        $memberName = trim($user['first_name'] . ' ' . $user['last_name']);
        
        if (file_exists($templatePath)) {
            $body = file_get_contents($templatePath);
            $body = str_replace('{first_name}', $user['first_name'], $body);
            $body = str_replace('{full_name}', $memberName, $body);
            $body = str_replace('{email}', $user['email'], $body);
        } else {
            // Fallback template
            $body = "
                <h2>Welcome, {$user['first_name']}!</h2>
                <p>Thank you for registering with us. Your account has been created successfully.</p>
                <p>You can now:</p>
                <ul>
                    <li>Browse and RSVP to events</li>
                    <li>View your event history</li>
                    <li>Manage your profile</li>
                </ul>
                <p>If you set a password, you can log in with your email and password. Otherwise, you can use the magic link login option.</p>
                <p>Welcome aboard!</p>
            ";
        }

        $subject = "Welcome! Your Account Has Been Created";

        $this->emailService->sendEmail(
            $user['email'],
            $subject,
            $body,
            $organizationId,
            [
                'email_type' => 'welcome',
                'user_id' => $user['id']
            ]
        );
    }
}
