<?php
/**
 * Simple QR Code Page Error Debug
 */

// Enable error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<pre>";
echo "=== QR CODE PAGE DEBUG ===\n\n";

require_once __DIR__ . '/bootstrap.php';
// Step 1: Check vendor/autoload
$autoloadPath = HC_PROJECT_ROOT . '/vendor/autoload.php';
echo "1. Autoload path: $autoloadPath\n";
echo "   Exists: " . (file_exists($autoloadPath) ? 'YES' : 'NO') . "\n\n";

if (!file_exists($autoloadPath)) {
    echo "ERROR: Composer autoload not found!\n";
    echo "The vendor directory is missing. You need to run 'composer install' on the server.\n";
    exit;
}

// Step 2: Try to load autoload
try {
    require_once $autoloadPath;
    echo "2. Autoload loaded: SUCCESS\n\n";
} catch (\Throwable $e) {
    echo "2. Autoload loaded: FAILED\n";
    echo "   Error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
    exit;
}

// Step 3: Check if classes exist
echo "3. Checking required classes:\n";
$classes = [
    'Headcount\\Middleware\\PortalAuthMiddleware',
    'Headcount\\Helpers\\Database',
    'Headcount\\Helpers\\Security',
    'Headcount\\Services\\QRCodeService'
];

foreach ($classes as $class) {
    echo "   $class: " . (class_exists($class) ? 'EXISTS' : 'MISSING') . "\n";
}
echo "\n";

// Step 4: Try to start session
try {
    use Headcount\Helpers\Security;
    
    Security::configureSession();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    echo "4. Session started: SUCCESS\n";
    echo "   Session ID: " . session_id() . "\n";
    echo "   Logged in: " . (isset($_SESSION['portal_member_id']) ? 'YES (ID: ' . $_SESSION['portal_member_id'] . ')' : 'NO') . "\n\n";
} catch (\Throwable $e) {
    echo "4. Session start: FAILED\n";
    echo "   Error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
}

// Step 5: Try to load config
$configFile = HC_PROJECT_ROOT . '/config/config.php';
echo "5. Config file: $configFile\n";
echo "   Exists: " . (file_exists($configFile) ? 'YES' : 'NO') . "\n";

if (file_exists($configFile)) {
    try {
        $config = require $configFile;
        echo "   Loaded: SUCCESS\n\n";
    } catch (\Throwable $e) {
        echo "   Loaded: FAILED\n";
        echo "   Error: " . $e->getMessage() . "\n\n";
        exit;
    }
} else {
    echo "   ERROR: Config file not found!\n\n";
    exit;
}

// Step 6: Try to initialize database
echo "6. Database initialization:\n";
try {
    use Headcount\Helpers\Database;
    Database::getInstance($config['database']);
    echo "   Status: SUCCESS\n\n";
} catch (\Throwable $e) {
    echo "   Status: FAILED\n";
    echo "   Error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n\n";
}

// Step 7: Try authentication check
echo "7. Authentication check:\n";
try {
    use Headcount\Middleware\PortalAuthMiddleware;
    
    $isAuth = PortalAuthMiddleware::isAuthenticated();
    echo "   Is authenticated: " . ($isAuth ? 'YES' : 'NO') . "\n";
    
    if ($isAuth) {
        $memberId = PortalAuthMiddleware::getMemberId();
        echo "   Member ID: $memberId\n";
        
        $member = PortalAuthMiddleware::getMember();
        echo "   Member name: " . ($member['name'] ?? 'N/A') . "\n";
    }
} catch (\Throwable $e) {
    echo "   Status: FAILED\n";
    echo "   Error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "   Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== END DEBUG ===\n";
echo "</pre>";
