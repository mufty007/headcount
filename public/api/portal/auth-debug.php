<?php
/**
 * Auth Debug Script
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}

$config = require HC_PROJECT_ROOT . '/config/config.php';
if (($config['app']['environment'] ?? 'production') !== 'development') {
    http_response_code(404);
    exit;
}

// Enable error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<pre>";
echo "=== PORTAL AUTH DEBUG ===\n\n";

// Load autoload
$autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
echo "1. Loading autoload: $autoloadPath\n";
if (!file_exists($autoloadPath)) {
    die("ERROR: Autoload not found at $autoloadPath\n");
}
require_once $autoloadPath;
echo "   Autoload loaded successfully.\n\n";

use Headcount\Controllers\PortalAuthController;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Load config
$configFile = HC_PROJECT_ROOT . '/config/config.php';
echo "2. Loading config: $configFile\n";
if (!file_exists($configFile)) {
    die("ERROR: Config not found.\n");
}
$config = require $configFile;
echo "   Config loaded.\n\n";

// Initialize database
echo "3. Initializing database...\n";
try {
    Database::getInstance($config['database']);
    echo "   Database initialized.\n\n";
} catch (\Exception $e) {
    echo "   ERROR: Database initialization failed: " . $e->getMessage() . "\n\n";
    exit;
}

// Check users table structure
echo "4. Checking users table structure...\n";
try {
    $db = Database::getInstance();
    $columns = $db->query("SHOW COLUMNS FROM users");
    echo "   Columns in 'users' table:\n";
    $columnNames = [];
    foreach ($columns as $column) {
        $columnNames[] = $column['Field'];
        echo "   - " . $column['Field'] . " (" . $column['Type'] . ")\n";
    }
    
    $required = ['email', 'password_hash', 'role', 'status', 'last_login_at'];
    foreach ($required as $req) {
        if (!in_array($req, $columnNames)) {
            echo "   !!! MISSING COLUMN: $req !!!\n";
        }
    }
    echo "\n";
} catch (\Exception $e) {
    echo "   ERROR: Could not check table structure: " . $e->getMessage() . "\n\n";
}

// Test Controller instantiation
echo "5. Testing PortalAuthController instantiation...\n";
try {
    $controller = new PortalAuthController();
    echo "   Controller instantiated successfully.\n\n";
} catch (\Throwable $e) {
    echo "   ERROR: Controller instantiation failed!\n";
    echo "   Message: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "   Trace:\n" . $e->getTraceAsString() . "\n\n";
}

echo "=== DEBUG END ===\n";
echo "</pre>";
