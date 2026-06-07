<?php
/**
 * QR Code API Debug
 * Test the QR code endpoint directly
 */

// Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "=== QR CODE API DEBUG ===\n\n";

require_once __DIR__ . '/bootstrap.php';
// Test 1: Check if vendor/autoload exists
$autoloadPath = HC_PROJECT_ROOT . '/vendor/autoload.php';
echo "1. Checking autoload: " . ($autoloadPath) . "\n";
echo "   Exists: " . (file_exists($autoloadPath) ? 'YES' : 'NO') . "\n\n";

if (!file_exists($autoloadPath)) {
    echo "ERROR: Composer autoload not found. Run 'composer install'\n";
    exit;
}

require_once $autoloadPath;

// Test 2: Check if QRCodeService exists
echo "2. Checking QRCodeService class:\n";
echo "   Class exists: " . (class_exists('Headcount\\Services\\QRCodeService') ? 'YES' : 'NO') . "\n\n";

// Test 3: Check if endroid/qr-code library is installed
echo "3. Checking QR Code library:\n";
echo "   Builder class exists: " . (class_exists('Endroid\\QrCode\\Builder\\Builder') ? 'YES' : 'NO') . "\n";
echo "   SvgWriter class exists: " . (class_exists('Endroid\\QrCode\\Writer\\SvgWriter') ? 'YES' : 'NO') . "\n\n";

// Test 4: Try to load config and database
echo "4. Testing config and database:\n";
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (file_exists($configFile)) {
    echo "   Config exists: YES\n";
    $config = require $configFile;
    
    try {
        use Headcount\Helpers\Database;
        use Headcount\Helpers\Security;
        
        Security::configureSession();
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        Database::getInstance($config['database']);
        echo "   Database connection: SUCCESS\n";
    } catch (\Exception $e) {
        echo "   Database connection: FAILED - " . $e->getMessage() . "\n";
    }
} else {
    echo "   Config exists: NO\n";
}

echo "\n5. Testing QRCodeService:\n";
try {
    use Headcount\Services\QRCodeService;
    use Headcount\Middleware\PortalAuthMiddleware;
    
    // Check if user is logged in
    if (PortalAuthMiddleware::isAuthenticated()) {
        $memberId = PortalAuthMiddleware::getMemberId();
        echo "   User authenticated: YES (Member ID: $memberId)\n";
        
        $qrService = new QRCodeService();
        $qrData = $qrService->generateQRCodeData($memberId);
        
        if ($qrData) {
            echo "   QR Data generated: YES\n";
            echo "   QR Code: " . substr($qrData['full_code'], 0, 50) . "...\n";
        } else {
            echo "   QR Data generated: NO\n";
        }
    } else {
        echo "   User authenticated: NO (Please log in first)\n";
        echo "   Visit: https://events.imcaindy.org/portal/login.php\n";
    }
} catch (\Exception $e) {
    echo "   ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
}

echo "\n=== END DEBUG ===\n";
