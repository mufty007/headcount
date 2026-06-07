<?php

/**
 * Test Configuration Sample
 * Copy this to config-test.php for testing
 */

return [
    // Database Configuration (use separate test database)
    'database' => [
        'host' => 'localhost',
        'database' => 'headcount_events_test',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],

    // Application Configuration
    'app' => [
        'name' => 'Headcount Events Test',
        'url' => 'http://localhost/Headcount',
        'timezone' => 'America/Indiana/Indianapolis',
        'debug' => true,
        'environment' => 'testing',
    ],

    // Security Configuration
    'security' => [
        'encryption_key' => 'test_encryption_key_change_in_production',
        'session_lifetime' => 86400,
        'password_min_length' => 8,
        'max_login_attempts' => 5,
        'lockout_duration' => 1800,
    ],

    // Stripe Configuration (use test keys)
    'stripe' => [
        'publishable_key' => 'pk_test_...',
        'secret_key' => 'sk_test_...',
        'webhook_secret' => 'whsec_test_...',
        'test_mode' => true,
    ],

    'cron' => [
        'stripe_reconcile_secret' => '',
    ],

    // SMTP2GO Configuration (use test API key)
    'smtp2go' => [
        'api_key' => 'test_api_key',
        'from_email' => 'test@example.com',
        'from_name' => 'Test Organization',
        'reply_to' => 'test@example.com',
    ],

    // File Upload Configuration
    'uploads' => [
        'max_file_size' => 10485760, // 10MB
        'allowed_image_types' => ['image/jpeg', 'image/png', 'image/gif'],
        'upload_path' => __DIR__ . '/../tests/uploads/',
    ],

    // Logging Configuration
    'logging' => [
        'enabled' => true,
        'level' => 'DEBUG',
        'log_path' => __DIR__ . '/../tests/logs/',
    ],
];
