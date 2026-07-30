<?php

/**
 * Configuration Sample File
 * Copy this file to config.php and update with your settings
 */

return [
    // Database Configuration
    'database' => [
        'host' => 'localhost',
        'database' => 'headcount_events',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],

    // Application Configuration
    'app' => [
        'name' => 'IMCA',
        'url' => 'http://localhost/Headcount',
        'timezone' => 'America/Indiana/Indianapolis',
        'debug' => true,
        'environment' => 'development', // development, staging, production
    ],

    // Security Configuration
    'security' => [
        'encryption_key' => '', // Set via HEADCOUNT_ENCRYPTION_KEY env or app.local.php — required in production
        'session_lifetime' => 86400, // 24 hours in seconds
        'password_min_length' => 8,
        'max_login_attempts' => 5,
        'lockout_duration' => 1800, // 30 minutes in seconds
    ],

    // Stripe Configuration
    'stripe' => [
        'publishable_key' => '',
        'secret_key' => '',
        'webhook_secret' => '',
        'test_mode' => true,
    ],

    // Optional: HTTP cron for Stripe pending reconciliation (see docs/STRIPE_WEBHOOKS.md)
    'cron' => [
        'stripe_reconcile_secret' => '',
    ],

    // SMTP2GO Configuration
    'smtp2go' => [
        'api_key' => '',
        'from_email' => '',
        'from_name' => '',
        'reply_to' => '',
    ],

    // File Upload Configuration
    'uploads' => [
        'max_file_size' => 10485760, // 10MB in bytes
        'allowed_image_types' => ['image/jpeg', 'image/png', 'image/gif'],
        'upload_path' => __DIR__ . '/../uploads/',
    ],

    // Email Configuration
    'email' => [
        'rate_limit' => 50, // emails per minute
        'batch_size' => 50,
    ],

    // Logging Configuration
    'logging' => [
        'enabled' => true,
        'level' => 'INFO', // DEBUG, INFO, WARNING, ERROR
        'log_path' => __DIR__ . '/../logs/',
    ],
];
