<?php

namespace Headcount\Controllers;

use Headcount\Models\Organization;
use Headcount\Models\User;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Helpers\Utilities;
use Headcount\Helpers\OrgTimeZone;
use Headcount\Helpers\Security;
use Headcount\Helpers\Validator;
use Headcount\Core\FileUpload;
use Headcount\Integrations\SMTP2GOService;
use Headcount\Integrations\StripeService;
use Headcount\Core\Bootstrap;
use Stripe\Stripe;
use Stripe\Account;

/**
 * Settings Controller
 * Handles organization and account settings
 */
class SettingsController
{
    private $organizationModel;
    private $userModel;
    private $config;

    public function __construct()
    {
        $this->organizationModel = new Organization();
        $this->userModel = new User();
        $this->config = Bootstrap::getConfig();
    }

    /**
     * Get all settings for organization
     */
    public function getSettings()
    {
        AuthMiddleware::requireAdmin();
        
        $organizationId = AuthMiddleware::getOrganizationId();
        $organization = $this->organizationModel->find($organizationId);
        
        if (!$organization) {
            return [
                'success' => false,
                'message' => 'Organization not found',
                'errors' => []
            ];
        }

        // Get current user for account tab
        $userId = AuthMiddleware::getUserId();
        $user = $this->userModel->find($userId);

        // Mask sensitive fields
        $settings = [
            'organization' => [
                'name' => $organization['name'] ?? '',
                'slug' => $organization['slug'] ?? '',
                'logo_path' => $organization['logo_path'] ?? null,
                'primary_color' => $organization['primary_color'] ?? '#3B82F6',
                'timezone' => OrgTimeZone::resolve($organization['timezone'] ?? null),
                'date_format' => $organization['date_format'] ?? 'Y-m-d',
                'time_format' => $organization['time_format'] ?? 'H:i',
            ],
            'email' => [
                'smtp_api_key' => $this->maskSensitiveValue($organization['smtp_api_key_encrypted'] ?? null, 'api'),
                'smtp_from_email' => $organization['smtp_from_email'] ?? '',
                'smtp_from_name' => $organization['smtp_from_name'] ?? '',
                'smtp_reply_to' => $organization['smtp_reply_to'] ?? '',
            ],
            'payments' => [
                'stripe_publishable_key' => $organization['stripe_publishable_key'] ?? '',
                'stripe_secret_key' => $this->maskSensitiveValue($organization['stripe_secret_key_encrypted'] ?? null, 'sk'),
                'stripe_webhook_secret' => $this->maskSensitiveValue($organization['stripe_webhook_secret_encrypted'] ?? null, 'whsec'),
                'stripe_test_mode' => (bool)($organization['stripe_test_mode'] ?? true),
            ],
            'notifications' => [
                'email_reminders_enabled' => (bool)($organization['email_reminders_enabled'] ?? true),
                'reminder_1week' => (bool)($organization['reminder_1week'] ?? true),
                'reminder_1day' => (bool)($organization['reminder_1day'] ?? true),
                'reminder_2hours' => (bool)($organization['reminder_2hours'] ?? true),
            ],
            'account' => [
                'first_name' => $user['first_name'] ?? '',
                'last_name' => $user['last_name'] ?? '',
                'email' => $user['email'] ?? '',
            ]
        ];

        return [
            'success' => true,
            'data' => $settings,
            'message' => 'Settings retrieved successfully'
        ];
    }

    /**
     * Update organization settings
     */
    public function updateOrganization($data)
    {
        AuthMiddleware::requireAdmin();
        
        $organizationId = AuthMiddleware::getOrganizationId();
        $errors = [];

        // Validate required fields
        if (empty($data['name'])) {
            $errors[] = ['field' => 'name', 'message' => 'Organization name is required'];
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $errors
            ];
        }

        // Generate slug from name if not provided
        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = $this->generateSlug($data['name'], $organizationId);
        }

        // Handle logo upload (check both $_FILES and data array for API requests)
        $logoFile = $_FILES['logo'] ?? ($data['_files']['logo'] ?? null);
        if ($logoFile && isset($logoFile['error']) && $logoFile['error'] === UPLOAD_ERR_OK) {
            try {
                $uploadConfig = $this->config['uploads'];
                $uploadConfig['allowed_types'] = ['image/jpeg', 'image/png', 'image/gif'];
                $uploadConfig['allowed_extensions'] = ['jpg', 'jpeg', 'png', 'gif'];
                $uploadConfig['max_size'] = 2097152; // 2MB for logos
                
                $fileUpload = new FileUpload($uploadConfig);
                $uploadResult = $fileUpload->upload($logoFile, 'logos');
                
                // Delete old logo if exists
                $org = $this->organizationModel->find($organizationId);
                if (!empty($org['logo_path'])) {
                    $oldLogoPath = $this->config['uploads']['upload_path'] . '/logos/' . basename($org['logo_path']);
                    if (file_exists($oldLogoPath)) {
                        @unlink($oldLogoPath);
                    }
                }
                
                $data['logo_path'] = 'logos/' . $uploadResult['filename'];
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'message' => 'Logo upload failed: ' . $e->getMessage(),
                    'errors' => []
                ];
            }
        }

        // Prepare update data
        $updateData = [];
        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['slug'])) $updateData['slug'] = $data['slug'];
        if (isset($data['logo_path'])) $updateData['logo_path'] = $data['logo_path'];
        if (isset($data['primary_color'])) $updateData['primary_color'] = $data['primary_color'];
        if (isset($data['timezone'])) $updateData['timezone'] = $data['timezone'];
        if (isset($data['date_format'])) $updateData['date_format'] = $data['date_format'];
        if (isset($data['time_format'])) $updateData['time_format'] = $data['time_format'];

        try {
            $organization = $this->organizationModel->update($organizationId, $updateData);
            
            return [
                'success' => true,
                'data' => $organization,
                'message' => 'Organization settings updated successfully'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update organization settings: ' . $e->getMessage(),
                'errors' => []
            ];
        }
    }

    /**
     * Update email settings
     */
    public function updateEmail($data)
    {
        AuthMiddleware::requireAdmin();
        
        $organizationId = AuthMiddleware::getOrganizationId();
        $errors = [];

        // Validate email
        if (!empty($data['smtp_from_email']) && !Validator::email($data['smtp_from_email'])) {
            $errors[] = ['field' => 'smtp_from_email', 'message' => 'Invalid email format'];
        }

        if (!empty($data['smtp_reply_to']) && !Validator::email($data['smtp_reply_to'])) {
            $errors[] = ['field' => 'smtp_reply_to', 'message' => 'Invalid email format'];
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $errors
            ];
        }

        $updateData = [];
        
        // Encrypt API key if provided
        if (!empty($data['smtp_api_key'])) {
            $encryptionKey = $this->getEncryptionKey();
            $updateData['smtp_api_key_encrypted'] = Security::encrypt($data['smtp_api_key'], $encryptionKey);
        }
        
        if (isset($data['smtp_from_email'])) $updateData['smtp_from_email'] = $data['smtp_from_email'];
        if (isset($data['smtp_from_name'])) $updateData['smtp_from_name'] = $data['smtp_from_name'];
        if (isset($data['smtp_reply_to'])) $updateData['smtp_reply_to'] = $data['smtp_reply_to'];

        try {
            $organization = $this->organizationModel->update($organizationId, $updateData);
            
            return [
                'success' => true,
                'data' => $organization,
                'message' => 'Email settings updated successfully'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update email settings: ' . $e->getMessage(),
                'errors' => []
            ];
        }
    }

    /**
     * Update payment settings
     */
    public function updatePayments($data)
    {
        AuthMiddleware::requireAdmin();
        
        $organizationId = AuthMiddleware::getOrganizationId();
        
        $updateData = [];
        
        if (isset($data['stripe_publishable_key'])) {
            $updateData['stripe_publishable_key'] = $data['stripe_publishable_key'];
        }
        
        // Encrypt secret key if provided
        if (!empty($data['stripe_secret_key'])) {
            $encryptionKey = $this->getEncryptionKey();
            $updateData['stripe_secret_key_encrypted'] = Security::encrypt($data['stripe_secret_key'], $encryptionKey);
        }
        
        // Encrypt webhook secret if provided
        if (!empty($data['stripe_webhook_secret'])) {
            $encryptionKey = $this->getEncryptionKey();
            $updateData['stripe_webhook_secret_encrypted'] = Security::encrypt($data['stripe_webhook_secret'], $encryptionKey);
        }
        
        if (isset($data['stripe_test_mode'])) {
            $updateData['stripe_test_mode'] = (bool)$data['stripe_test_mode'];
        }

        try {
            $organization = $this->organizationModel->update($organizationId, $updateData);
            
            return [
                'success' => true,
                'data' => $organization,
                'message' => 'Payment settings updated successfully'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update payment settings: ' . $e->getMessage(),
                'errors' => []
            ];
        }
    }

    /**
     * Update notification settings
     */
    public function updateNotifications($data)
    {
        AuthMiddleware::requireAdmin();
        
        $organizationId = AuthMiddleware::getOrganizationId();
        
        $updateData = [];
        
        if (isset($data['email_reminders_enabled'])) {
            $updateData['email_reminders_enabled'] = (bool)$data['email_reminders_enabled'];
        }
        if (isset($data['reminder_1week'])) {
            $updateData['reminder_1week'] = (bool)$data['reminder_1week'];
        }
        if (isset($data['reminder_1day'])) {
            $updateData['reminder_1day'] = (bool)$data['reminder_1day'];
        }
        if (isset($data['reminder_2hours'])) {
            $updateData['reminder_2hours'] = (bool)$data['reminder_2hours'];
        }

        try {
            $organization = $this->organizationModel->update($organizationId, $updateData);
            
            return [
                'success' => true,
                'data' => $organization,
                'message' => 'Notification settings updated successfully'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update notification settings: ' . $e->getMessage(),
                'errors' => []
            ];
        }
    }

    /**
     * Update account settings
     */
    public function updateAccount($data)
    {
        AuthMiddleware::requireAdmin();
        
        $userId = AuthMiddleware::getUserId();
        $errors = [];

        // Validate required fields
        if (empty($data['first_name'])) {
            $errors[] = ['field' => 'first_name', 'message' => 'First name is required'];
        }
        if (empty($data['last_name'])) {
            $errors[] = ['field' => 'last_name', 'message' => 'Last name is required'];
        }
        if (empty($data['email'])) {
            $errors[] = ['field' => 'email', 'message' => 'Email is required'];
        } elseif (!Validator::email($data['email'])) {
            $errors[] = ['field' => 'email', 'message' => 'Invalid email format'];
        }

        // Check if email is already taken
        $organizationId = AuthMiddleware::getOrganizationId();
        if ($this->userModel->emailExists($data['email'], $organizationId, $userId)) {
            $errors[] = ['field' => 'email', 'message' => 'Email is already in use'];
        }

        // Handle password change
        if (!empty($data['new_password'])) {
            if (empty($data['current_password'])) {
                $errors[] = ['field' => 'current_password', 'message' => 'Current password is required to change password'];
            } else {
                $user = $this->userModel->find($userId);
                if (!Security::verifyPassword($data['current_password'], $user['password_hash'] ?? '')) {
                    $errors[] = ['field' => 'current_password', 'message' => 'Current password is incorrect'];
                }
            }

            if ($data['new_password'] !== ($data['confirm_password'] ?? '')) {
                $errors[] = ['field' => 'confirm_password', 'message' => 'Passwords do not match'];
            }

            $passwordErrors = Security::validatePassword($data['new_password']);
            if (!empty($passwordErrors)) {
                foreach ($passwordErrors as $error) {
                    $errors[] = ['field' => 'new_password', 'message' => $error];
                }
            }
        }

        if (!empty($errors)) {
            return [
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $errors
            ];
        }

        $updateData = [
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
        ];

        if (!empty($data['new_password'])) {
            $updateData['password'] = $data['new_password'];
        }

        try {
            $user = $this->userModel->update($userId, $updateData);
            
            // Update session
            $_SESSION['email'] = $user['email'];
            $_SESSION['name'] = $user['first_name'] . ' ' . $user['last_name'];
            
            return [
                'success' => true,
                'data' => $user,
                'message' => 'Account settings updated successfully'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to update account settings: ' . $e->getMessage(),
                'errors' => []
            ];
        }
    }

    /**
     * Test email configuration
     */
    public function testEmail()
    {
        AuthMiddleware::requireAdmin();
        
        $organizationId = AuthMiddleware::getOrganizationId();
        $organization = $this->organizationModel->find($organizationId);
        
        if (empty($organization['smtp_api_key_encrypted'])) {
            return [
                'success' => false,
                'message' => 'SMTP API key not configured'
            ];
        }

        try {
            $encryptionKey = $this->getEncryptionKey();
            $apiKey = Security::decrypt($organization['smtp_api_key_encrypted'], $encryptionKey);
            
            $smtpService = new SMTP2GOService(
                $apiKey,
                $organization['smtp_from_email'] ?? null,
                $organization['smtp_from_name'] ?? null,
                $organization['smtp_reply_to'] ?? null
            );
            
            $userId = AuthMiddleware::getUserId();
            $user = $this->userModel->find($userId);
            $testEmail = $user['email'] ?? $organization['smtp_from_email'];
            
            if (empty($testEmail)) {
                return [
                    'success' => false,
                    'message' => 'No email address available for testing'
                ];
            }
            
            // Send test email to user's email
            try {
                $smtpService->sendEmail(
                    $testEmail,
                    'Headcount Events - Email Configuration Test',
                    '<p>This is a test email from your Headcount Events platform.</p><p>If you received this email, your SMTP configuration is working correctly!</p>'
                );
                
                return [
                    'success' => true,
                    'message' => 'Test email sent successfully to ' . $testEmail
                ];
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'message' => 'Failed to send test email: ' . $e->getMessage()
                ];
            }
            
            return [
                'success' => $result['success'],
                'message' => $result['message']
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Email test failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Test Stripe connection
     */
    public function testStripe()
    {
        AuthMiddleware::requireAdmin();
        
        $organizationId = AuthMiddleware::getOrganizationId();
        $organization = $this->organizationModel->find($organizationId);
        
        if (empty($organization['stripe_secret_key_encrypted'])) {
            return [
                'success' => false,
                'message' => 'Stripe secret key not configured'
            ];
        }

        try {
            $encryptionKey = $this->getEncryptionKey();
            $secretKey = Security::decrypt($organization['stripe_secret_key_encrypted'], $encryptionKey);
            
            // Set API key
            Stripe::setApiKey($secretKey);
            
            // Try to retrieve account info to test connection
            $account = Account::retrieve();
            
            return [
                'success' => true,
                'message' => 'Stripe connection successful',
                'data' => [
                    'account_id' => $account->id ?? 'Unknown',
                    'test_mode' => $organization['stripe_test_mode'] ?? true
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'Stripe test failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Generate slug from name
     */
    private function generateSlug($name, $excludeOrgId = null)
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name)));
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');
        
        // Check if slug exists
        $db = \Headcount\Helpers\Database::getInstance();
        $sql = "SELECT id FROM organizations WHERE slug = :slug";
        $params = ['slug' => $slug];
        
        if ($excludeOrgId) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeOrgId;
        }
        
        $existing = $db->queryOne($sql, $params);
        
        if ($existing) {
            $slug .= '-' . time();
        }
        
        return $slug;
    }

    /**
     * Mask sensitive value for display
     */
    private function maskSensitiveValue($encryptedValue, $prefix = '')
    {
        if (empty($encryptedValue)) {
            return '';
        }
        
        // Try to decrypt to get length, but show masked
        try {
            $encryptionKey = $this->getEncryptionKey();
            $decrypted = Security::decrypt($encryptedValue, $encryptionKey);
            if ($decrypted) {
                $length = strlen($decrypted);
                $visible = substr($decrypted, 0, 4);
                $hidden = str_repeat('*', max(0, $length - 8));
                $end = substr($decrypted, -4);
                return $prefix ? $prefix . '_' . $visible . $hidden . $end : $visible . $hidden . $end;
            }
        } catch (\Exception $e) {
            // If decryption fails, just show masked
        }
        
        return '****';
    }

    /**
     * Get encryption key from config
     */
    private function getEncryptionKey()
    {
        // Use a consistent key from config or generate one
        // In production, this should be in config file
        $key = $this->config['security']['encryption_key'] ?? null;
        
        if (empty($key)) {
            // Fallback: use app name + database name as key (not secure, but works for dev)
            $dbName = $this->config['database']['name'] ?? 'headcount_dev';
            $key = hash('sha256', $this->config['app']['name'] . $dbName);
        }
        
        // Ensure key is 32 bytes for AES-256
        if (strlen($key) < 32) {
            $key = hash('sha256', $key . 'headcount_salt');
        }
        $key = substr($key, 0, 32);
        
        return $key;
    }
}
