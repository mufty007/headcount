<?php
/**
 * Application configuration.
 *
 * Database credentials (merged in order; later steps override earlier):
 * 1. Defaults (local XAMPP-style).
 * 2. `.env` in project root, then `config/.env` (Hostinger: put secrets here if you prefer next to config.php).
 * 3. Environment variables: DB_* and HEADCOUNT_DB_* (see map below).
 * 4. `config/database.php` — optional; gitignored; **upload this on Hostinger** with host/name/user/password (FTP-friendly).
 * 5. `config/database.local.php` — optional; gitignored; wins over everything above (local dev).
 * 6. `HEADCOUNT_APP_URL` / `HEADCOUNT_PORTAL_URL` in `.env` or `config/.env` (production URLs for emails & links).
 * 7. `config/app.local.php` — optional; gitignored; overrides app.url / portal_url on the server (see app.local.example.php).
 * 8. `HEADCOUNT_ENCRYPTION_KEY` in `.env` or `config/.env` — required in production for encrypted SMTP/Stripe fields.
 *
 * Hostinger: if git/SFTP skips gitignored files, use **File Manager → New file → `config/database.php`**
 * (copy from `database.php.example`) and paste your MySQL password from hPanel. Same keys as `database.local.example.php`.
 */

$projectRoot = dirname(__DIR__);

$loadEnvFile = static function (string $path): void {
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || strncmp($line, '#', 1) === 0) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $name = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if ($name === '') {
            continue;
        }
        if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
            $value = stripcslashes(substr($value, 1, -1));
        }
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
};

// Root .env first, then config/.env (overrides — easy on shared hosting next to this file).
$loadEnvFile($projectRoot . DIRECTORY_SEPARATOR . '.env');
$loadEnvFile(__DIR__ . DIRECTORY_SEPARATOR . '.env');

$envVal = static function (string $key): ?string {
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return $v;
    }
    if (isset($_ENV[$key]) && (string) $_ENV[$key] !== '') {
        return (string) $_ENV[$key];
    }
    if (isset($_SERVER[$key]) && (string) $_SERVER[$key] !== '') {
        return (string) $_SERVER[$key];
    }

    return null;
};

$database = [
    'host' => 'localhost',
    'name' => 'headcount_dev',
    'username' => 'root',
    'password' => '',
    'charset' => 'utf8mb4',
];

$mapEnv = [
    'DB_HOST' => 'host',
    'DB_DATABASE' => 'name',
    'DB_USERNAME' => 'username',
    'DB_PASSWORD' => 'password',
    'HEADCOUNT_DB_HOST' => 'host',
    'HEADCOUNT_DB_NAME' => 'name',
    'HEADCOUNT_DB_USER' => 'username',
    'HEADCOUNT_DB_PASSWORD' => 'password',
];
foreach ($mapEnv as $envKey => $dbKey) {
    $v = $envVal($envKey);
    if ($v !== null) {
        $database[$dbKey] = $v;
    }
}

$mergeDatabasePhpFile = static function (array $database, string $path): array {
    if (!is_file($path)) {
        return $database;
    }
    $local = require $path;
    if (!is_array($local)) {
        return $database;
    }
    if (isset($local['database']) && !isset($local['name'])) {
        $local['name'] = $local['database'];
        unset($local['database']);
    }
    foreach (['host', 'name', 'username', 'charset'] as $k) {
        if (!empty($local[$k])) {
            $database[$k] = (string) $local[$k];
        }
    }
    if (array_key_exists('password', $local)) {
        $database['password'] = (string) $local['password'];
    }

    return $database;
};

// Production/SFTP: database.php then local override: database.local.php
$database = $mergeDatabasePhpFile($database, __DIR__ . DIRECTORY_SEPARATOR . 'database.php');
$database = $mergeDatabasePhpFile($database, __DIR__ . DIRECTORY_SEPARATOR . 'database.local.php');

$appUrl = 'http://localhost/Headcount';
$portalUrl = '';
$portalPublicBaseUrl = '';
$appEnvironment = 'development';
$appDebug = true;

$v = $envVal('HEADCOUNT_APP_URL');
if ($v !== null) {
    $appUrl = rtrim($v, '/');
}
$v = $envVal('HEADCOUNT_PORTAL_URL');
if ($v !== null) {
    $portalUrl = rtrim($v, '/');
}
$v = $envVal('HEADCOUNT_PORTAL_PUBLIC_BASE_URL');
if ($v !== null) {
    $portalPublicBaseUrl = rtrim($v, '/');
}
$v = $envVal('HEADCOUNT_ENV') ?? $envVal('APP_ENV');
if ($v !== null) {
    $appEnvironment = $v;
}
$v = $envVal('HEADCOUNT_DEBUG');
if ($v !== null) {
    $appDebug = filter_var($v, FILTER_VALIDATE_BOOLEAN);
}
$encryptionKey = '';
$sessionCookieSecure = true;
$v = $envVal('HEADCOUNT_ENCRYPTION_KEY');
if ($v !== null) {
    $encryptionKey = $v;
}

$appLocalPath = __DIR__ . DIRECTORY_SEPARATOR . 'app.local.php';
if (is_file($appLocalPath)) {
    $appLocal = require $appLocalPath;
    if (is_array($appLocal)) {
        if (!empty($appLocal['url'])) {
            $appUrl = rtrim((string) $appLocal['url'], '/');
        }
        if (array_key_exists('portal_url', $appLocal)) {
            $portalUrl = rtrim((string) $appLocal['portal_url'], '/');
        }
        if (!empty($appLocal['public_base_url'])) {
            $portalPublicBaseUrl = rtrim((string) $appLocal['public_base_url'], '/');
        }
        if (!empty($appLocal['environment'])) {
            $appEnvironment = (string) $appLocal['environment'];
        }
        if (array_key_exists('debug', $appLocal)) {
            $appDebug = (bool) $appLocal['debug'];
        }
        if (isset($appLocal['session']['cookie_secure'])) {
            $sessionCookieSecure = (bool) $appLocal['session']['cookie_secure'];
        }
    }
}

return [
    'database' => $database,

    // Application Configuration
    'app' => [
        'name' => 'Headcount Events',
        'url' => $appUrl,
        // Optional: public portal / QR links when different from app.url (e.g. https://events.imcaindy.org)
        'portal_url' => $portalUrl,
        // PHP default timezone when a script has no org context; prefer organizations.timezone in Admin → Settings.
        'timezone' => 'America/Indiana/Indianapolis',
        'debug' => $appDebug,
        'environment' => $appEnvironment, // development, staging, production
    ],

    // Portal: when guests open Upcoming Events without ?organization_id=, use this org (multi-tenant).
    // public_base_url: same role as app.portal_url; either can be set (app.portal_url wins if both set).
    'portal' => [
        'organization_id' => null,
        'public_base_url' => $portalPublicBaseUrl,
    ],

    // Session Configuration
    'session' => [
        'lifetime' => 86400, // 24 hours in seconds
        'cookie_name' => 'headcount_session',
        'cookie_secure' => $sessionCookieSecure, // Override via config/app.local.php session.cookie_secure for local HTTP dev
        'cookie_httponly' => true,
        'cookie_samesite' => 'Strict',
    ],

    // Security Configuration
    'security' => [
        'encryption_key' => $encryptionKey,
        'password_min_length' => 8,
        'password_require_uppercase' => true,
        'password_require_number' => true,
        'max_login_attempts' => 5,
        'lockout_duration' => 1800, // 30 minutes in seconds
        'bcrypt_cost' => 12,
    ],

    // Stripe Configuration (optional)
    'stripe' => [
        'publishable_key' => '',
        'secret_key' => '',
        'webhook_secret' => '',
        'test_mode' => true,
    ],

    // Scheduled / external jobs (optional)
    'cron' => [
        // Shared secret for HTTP cron via X-Cron-Secret header on public/api/cron-stripe-reconcile.php (long random string).
        // Leave empty to disable the HTTP cron URL (use CLI script only).
        'stripe_reconcile_secret' => '',
    ],

    // SMTP2GO — both api_key and from_email are required for outbound mail (password reset, campaigns, etc.)
    'smtp2go' => [
        'api_key' => '',
        'from_email' => '',
        'from_name' => '',
        'reply_to' => '',
    ],

    // File Upload Configuration
    'uploads' => [
        'max_file_size' => 10485760, // 10MB in bytes
        'allowed_extensions' => ['csv', 'jpg', 'jpeg', 'png', 'gif'],
        'upload_path' => __DIR__ . '/../uploads',
    ],

    // Logging Configuration
    'logging' => [
        'enabled' => true,
        'level' => 'DEBUG', // DEBUG, INFO, WARNING, ERROR
        'log_path' => __DIR__ . '/../logs',
        'max_log_size' => 10485760, // 10MB
    ],
];
