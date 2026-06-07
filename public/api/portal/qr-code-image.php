<?php
/**
 * Simple QR Code Image Generator
 * Standalone endpoint that generates QR codes using Google Charts API
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use Headcount\Services\QRCodeService;
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Load config
$configFile = __DIR__ . '/../../../config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "Configuration not found";
    exit;
}

$config = require $configFile;

// Initialize database
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "Database initialization failed";
    exit;
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

// Check authentication
if (!PortalAuthMiddleware::isAuthenticated()) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo "Unauthorized";
    exit;
}

$memberId = PortalAuthMiddleware::getMemberId();

if (!$memberId) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo "Unauthorized: No member ID";
    exit;
}

// Get QR code data
try {
    $qrService = new QRCodeService();
    $qrData = $qrService->generateQRCodeData($memberId);
    
    if (!$qrData || empty($qrData['full_code'])) {
        http_response_code(404);
        header('Content-Type: text/plain');
        echo "QR code not found";
        exit;
    }
    
    // Use Google Charts API to generate QR code
    $qrCode = $qrData['full_code'];
    $encodedData = urlencode($qrCode);
    
    // Redirect to Google Charts QR Code API
    header('Location: https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl=' . $encodedData);
    exit;
    
} catch (\Throwable $e) {
    error_log("QR Code generation error: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "Error generating QR code";
    exit;
}
