<?php

/**
 * Pre-Installation Requirements Check
 * Checks server requirements before installation
 */

function checkRequirements()
{
    $checks = [
        'php_version' => [
            'name' => 'PHP Version',
            'required' => '8.0.0',
            'current' => PHP_VERSION,
            'status' => version_compare(PHP_VERSION, '8.0.0', '>='),
            'message' => version_compare(PHP_VERSION, '8.0.0', '>=') 
                ? 'PHP version is compatible' 
                : 'PHP 8.0 or higher is required'
        ],
        'mysqli' => [
            'name' => 'MySQLi Extension',
            'required' => 'Required',
            'current' => extension_loaded('mysqli') ? 'Installed' : 'Not Installed',
            'status' => extension_loaded('mysqli'),
            'message' => extension_loaded('mysqli') 
                ? 'MySQLi extension is available' 
                : 'MySQLi extension is required'
        ],
        'curl' => [
            'name' => 'cURL Extension',
            'required' => 'Required',
            'current' => extension_loaded('curl') ? 'Installed' : 'Not Installed',
            'status' => extension_loaded('curl'),
            'message' => extension_loaded('curl') 
                ? 'cURL extension is available' 
                : 'cURL extension is required'
        ],
        'gd' => [
            'name' => 'GD or Imagick Extension',
            'required' => 'Required',
            'current' => (extension_loaded('gd') || extension_loaded('imagick')) ? 'Installed' : 'Not Installed',
            'status' => extension_loaded('gd') || extension_loaded('imagick'),
            'message' => (extension_loaded('gd') || extension_loaded('imagick'))
                ? 'Image processing extension is available'
                : 'GD or Imagick extension is required'
        ],
        'mbstring' => [
            'name' => 'mbstring Extension',
            'required' => 'Required',
            'current' => extension_loaded('mbstring') ? 'Installed' : 'Not Installed',
            'status' => extension_loaded('mbstring'),
            'message' => extension_loaded('mbstring')
                ? 'mbstring extension is available'
                : 'mbstring extension is required'
        ],
        'openssl' => [
            'name' => 'OpenSSL Extension',
            'required' => 'Required',
            'current' => extension_loaded('openssl') ? 'Installed' : 'Not Installed',
            'status' => extension_loaded('openssl'),
            'message' => extension_loaded('openssl')
                ? 'OpenSSL extension is available'
                : 'OpenSSL extension is required'
        ],
        'json' => [
            'name' => 'JSON Extension',
            'required' => 'Required',
            'current' => extension_loaded('json') ? 'Installed' : 'Not Installed',
            'status' => extension_loaded('json'),
            'message' => extension_loaded('json')
                ? 'JSON extension is available'
                : 'JSON extension is required'
        ],
        'session' => [
            'name' => 'Session Extension',
            'required' => 'Required',
            'current' => extension_loaded('session') ? 'Installed' : 'Not Installed',
            'status' => extension_loaded('session'),
            'message' => extension_loaded('session')
                ? 'Session extension is available'
                : 'Session extension is required'
        ],
        'zip' => [
            'name' => 'Zip Extension',
            'required' => 'Recommended',
            'current' => extension_loaded('zip') ? 'Installed' : 'Not Installed',
            'status' => extension_loaded('zip'),
            'message' => extension_loaded('zip')
                ? 'Zip extension is available (recommended for backups)'
                : 'Zip extension is recommended for backups'
        ],
        'writable_config' => [
            'name' => 'Config Directory Writable',
            'required' => 'Required',
            'current' => is_writable(__DIR__ . '/../config') || (!file_exists(__DIR__ . '/../config') && is_writable(__DIR__ . '/..')) ? 'Writable' : 'Not Writable',
            'status' => is_writable(__DIR__ . '/../config') || (!file_exists(__DIR__ . '/../config') && is_writable(__DIR__ . '/..')),
            'message' => (is_writable(__DIR__ . '/../config') || (!file_exists(__DIR__ . '/../config') && is_writable(__DIR__ . '/..')))
                ? 'Config directory is writable'
                : 'Config directory must be writable'
        ],
        'writable_uploads' => [
            'name' => 'Uploads Directory Writable',
            'required' => 'Required',
            'current' => is_writable(__DIR__ . '/../uploads') || (!file_exists(__DIR__ . '/../uploads') && is_writable(__DIR__ . '/..')) ? 'Writable' : 'Not Writable',
            'status' => is_writable(__DIR__ . '/../uploads') || (!file_exists(__DIR__ . '/../uploads') && is_writable(__DIR__ . '/..')),
            'message' => (is_writable(__DIR__ . '/../uploads') || (!file_exists(__DIR__ . '/../uploads') && is_writable(__DIR__ . '/..')))
                ? 'Uploads directory is writable'
                : 'Uploads directory must be writable'
        ],
        'writable_logs' => [
            'name' => 'Logs Directory Writable',
            'required' => 'Required',
            'current' => is_writable(__DIR__ . '/../logs') || (!file_exists(__DIR__ . '/../logs') && is_writable(__DIR__ . '/..')) ? 'Writable' : 'Not Writable',
            'status' => is_writable(__DIR__ . '/../logs') || (!file_exists(__DIR__ . '/../logs') && is_writable(__DIR__ . '/..')),
            'message' => (is_writable(__DIR__ . '/../logs') || (!file_exists(__DIR__ . '/../logs') && is_writable(__DIR__ . '/..')))
                ? 'Logs directory is writable'
                : 'Logs directory must be writable'
        ],
    ];

    $allPassed = true;
    foreach ($checks as $check) {
        if ($check['required'] === 'Required' && !$check['status']) {
            $allPassed = false;
        }
    }

    return [
        'checks' => $checks,
        'all_passed' => $allPassed
    ];
}

// If called directly, return JSON
if (php_sapi_name() !== 'cli' && isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode(checkRequirements());
    exit;
}

return checkRequirements();
