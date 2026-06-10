<?php
// Start output buffering to prevent any accidental output
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

// Disable error display, we'll handle errors ourselves
ini_set('display_errors', 0);
ini_set('html_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Set error handler to catch any errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $msg = "PHP Error [$errno]: $errstr in $errfile on line $errline";
    error_log($msg);
    if (!(error_reporting() & $errno)) return false;
    
    while (ob_get_level() > 0) ob_end_clean();
    $isImage = (strpos($_SERVER['REQUEST_URI'] ?? '', '/qr-code/image') !== false);
    if ($isImage) {
        header('Content-Type: text/plain', true, 500);
        echo "Error: " . htmlspecialchars($msg);
    } else {
        header('Content-Type: application/json', true, 500);
        echo json_encode(['success' => false, 'message' => 'Internal Server Error', 'error' => $msg]);
    }
    exit;
}, E_ALL);

// Set exception handler
set_exception_handler(function($exception) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $isImage = (strpos($_SERVER['REQUEST_URI'] ?? '', '/qr-code/image') !== false);
    if ($isImage) {
        header('Content-Type: text/plain', true);
        http_response_code(500);
        echo "Error: " . htmlspecialchars($exception->getMessage());
    } else {
        header('Content-Type: application/json', true);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred: ' . $exception->getMessage()
        ]);
    }
    error_log("Uncaught exception: " . $exception->getMessage() . " in " . $exception->getFile() . ":" . $exception->getLine());
    exit;
});

// Set shutdown function to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        $isImage = (strpos($_SERVER['REQUEST_URI'] ?? '', '/qr-code/image') !== false);
        if ($isImage) {
            header('Content-Type: text/plain', true, 500);
            echo "Fatal Error: " . htmlspecialchars($error['message']);
        } else {
            header('Content-Type: application/json', true, 500);
            echo json_encode([
                'success' => false,
                'message' => 'Fatal Server Error: ' . $error['message'],
                'error' => $error
            ]);
        }
        error_log("Fatal Error: " . $error['message'] . " in " . $error['file'] . " on line " . $error['line']);
    }
});

/**
 * Portal QR Code API
 * Handles QR code generation and validation
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Services\QRCodeService;
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;

// Check if this is an image endpoint early - before setting JSON headers
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);
$isImageEndpoint = (strpos($path, '/qr-code/image') !== false || strpos($path, '/qr_code/image') !== false);

// Load config
$configFile = __DIR__ . '/../../../config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    if ($isImageEndpoint) {
        header('Content-Type: text/plain');
        echo "Configuration not found";
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Configuration not found']);
    }
    exit;
}

$config = require $configFile;

// Initialize database
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database initialization failed']);
    exit;
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Extract action from path
// First, try to use segments passed from the router (more reliable)
$action = '';
if (isset($GLOBALS['portal_api_segments']) && is_array($GLOBALS['portal_api_segments'])) {
    // Segments will be ['qr-code', 'image'] for /api/portal/qr-code/image
    // We want the segment after 'qr-code', which is index 1
    if (isset($GLOBALS['portal_api_segments'][1])) {
        $action = $GLOBALS['portal_api_segments'][1];
    }
    // If no second segment, action remains empty (base endpoint)
}

// Fallback: parse REQUEST_URI if segments not available
if (empty($action)) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $path = parse_url($requestUri, PHP_URL_PATH);
    $pathSegments = explode('/', trim($path, '/'));
    $qrCodePos = array_search('qr-code', $pathSegments);
    if ($qrCodePos !== false && isset($pathSegments[$qrCodePos + 1])) {
        $action = $pathSegments[$qrCodePos + 1];
    }
    // Also try 'qr_code' with underscore
    if (empty($action)) {
        $qrCodePos = array_search('qr_code', $pathSegments);
        if ($qrCodePos !== false && isset($pathSegments[$qrCodePos + 1])) {
            $action = $pathSegments[$qrCodePos + 1];
        }
    }
}

// Get request URI for endpoint detection
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

// Debug logging (only in debug mode)
if (isset($config['app']['debug']) && $config['app']['debug']) {
    error_log("QR Code API - Action: " . $action . ", Method: " . $method . ", URI: " . $requestUri . ", Segments: " . print_r($GLOBALS['portal_api_segments'] ?? [], true));
}

$qrService = new QRCodeService();

try {
    // GET /api/portal/qr-code - Get member's QR code data (requires auth)
    if (empty($action) && $method === 'GET') {
        // Set JSON header early
        header('Content-Type: application/json');
        
        PortalAuthMiddleware::requireAuth();
        $memberId = PortalAuthMiddleware::getMemberId();
        
        $qrData = $qrService->generateQRCodeData($memberId);
        
        if ($qrData) {
            echo json_encode([
                'success' => true,
                'qr_code' => $qrData['full_code'],
                'data' => $qrData['data']
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'QR code not found']);
        }
        exit;
    }

    // GET /api/portal/qr-code/image - Get QR code image (requires auth)
    // Check both action and direct URL match for robustness
    $isImageRequest = ($action === 'image' && $method === 'GET') || 
                      (strpos($requestUri, '/qr-code/image') !== false && $method === 'GET') ||
                      (strpos($requestUri, '/qr_code/image') !== false && $method === 'GET');
    
    if ($isImageRequest) {
        try {
            // Check authentication without redirecting (for API endpoints)
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
            
            // Get user's QR code data
            try {
                $qrData = $qrService->generateQRCodeData($memberId);
                
                if (!$qrData) {
                    error_log("QRCodeService returned null for member ID: " . $memberId);
                    http_response_code(404);
                    header('Content-Type: text/plain');
                    echo "QR code not found: User not found or unable to generate QR code";
                    exit;
                }
                
                if (empty($qrData['full_code'])) {
                    error_log("QRCodeService returned empty full_code for member ID: " . $memberId . " | Data: " . json_encode($qrData));
                    http_response_code(500);
                    header('Content-Type: text/plain');
                    echo "QR code data incomplete";
                    exit;
                }
            } catch (\Throwable $serviceException) {
                error_log("QRCodeService error: " . $serviceException->getMessage() . " | File: " . $serviceException->getFile() . " | Line: " . $serviceException->getLine() . " | Trace: " . $serviceException->getTraceAsString());
                
                // Clear any output buffers
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                
                http_response_code(500);
                header('Content-Type: text/plain');
                $errorMsg = "Error generating QR code data";
                if (isset($config['app']['debug']) && $config['app']['debug']) {
                    $errorMsg .= ": " . htmlspecialchars($serviceException->getMessage()) . " in " . htmlspecialchars($serviceException->getFile()) . ":" . $serviceException->getLine();
                }
                echo $errorMsg;
                exit;
            }

            // Generate QR code image using endroid/qr-code library
            $qrCode = $qrData['full_code'];
            
            // Check if QR code library classes exist
            if (!class_exists(Builder::class)) {
                error_log("QR Code library not found. Please run: composer install");
                http_response_code(503);
                header('Content-Type: text/plain');
                echo "QR code library not installed. Please run: composer install";
                exit;
            }
            
            try {
                // Check if QR code library classes exist
                if (!class_exists(Builder::class)) {
                    error_log("QR Code library not found. Using fallback method.");
                    
                    // Fallback: Use Google Charts API or redirect to a QR code generator
                    $qrCode = $qrData['full_code'];
                    $encodedData = urlencode($qrCode);
                    
                    // Clear any output buffers before redirect
                    while (ob_get_level() > 0) {
                        ob_end_clean();
                    }
                    
                    // Redirect to Google Charts QR Code API
                    header('Location: https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl=' . $encodedData);
                    exit;
                }
                
                // Build QR code
                $result = Builder::create()
                    ->writer(new SvgWriter())
                    ->data($qrCode)
                    ->encoding(new Encoding('UTF-8'))
                    ->errorCorrectionLevel(ErrorCorrectionLevel::High)
                    ->size(300)
                    ->margin(10)
                    ->build();
                
                // Get the result string
                if (!method_exists($result, 'getString')) {
                    throw new \Exception('QR code result does not have getString method');
                }
                
                $qrString = $result->getString();
                
                if (empty($qrString)) {
                    throw new \Exception('QR code generation returned empty string');
                }
                
                // Clear any output buffers before sending image
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                
                // Send headers and content
                header('Content-Type: image/svg+xml');
                header('Cache-Control: public, max-age=3600');
                echo $qrString;
                exit;
            } catch (\Throwable $buildException) {
                error_log("QR Code Builder error: " . $buildException->getMessage() . " | File: " . $buildException->getFile() . " | Line: " . $buildException->getLine() . " | Trace: " . $buildException->getTraceAsString());
                
                // Clear any output buffers
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                
                http_response_code(500);
                header('Content-Type: text/plain');
                $errorMsg = "Error building QR code image";
                if (isset($config['app']['debug']) && $config['app']['debug']) {
                    $errorMsg .= ": " . htmlspecialchars($buildException->getMessage()) . " in " . htmlspecialchars($buildException->getFile()) . ":" . $buildException->getLine();
                }
                echo $errorMsg;
                exit;
            }
        } catch (\Exception $e) {
            error_log("QR code image generation error: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
            http_response_code(500);
            header('Content-Type: text/plain');
            // Only show detailed error in development
            $errorMsg = "Error generating QR code";
            if (isset($config['app']['debug']) && $config['app']['debug']) {
                $errorMsg .= ": " . htmlspecialchars($e->getMessage());
            }
            echo $errorMsg;
            exit;
        }
    }

    // POST /api/portal/qr-code/validate - Validate QR code (for admin scanning)
    if ($action === 'validate' && $method === 'POST') {
        // This endpoint requires admin authentication (not portal auth)
        // For now, we'll allow it but in production should verify admin
        if (!isset($input)) {
            $input = json_decode(@file_get_contents('php://input'), true) ?? [];
        }
        $qrCode = $input['qr_code'] ?? '';
        
        $user = $qrService->validateQRCode($qrCode);
        
        if ($user) {
            echo json_encode([
                'success' => true,
                'user' => $user
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid or expired QR code'
            ]);
        }
        exit;
    }

    // 404 - Endpoint not found
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    
} catch (\Throwable $e) {
    http_response_code(500);
    error_log("Portal QR code API error: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString());
    
    // For image endpoint, return plain text error
    $isImageEndpoint = ($action === 'image') || 
                       (strpos($_SERVER['REQUEST_URI'] ?? '', '/qr-code/image') !== false) ||
                       (strpos($_SERVER['REQUEST_URI'] ?? '', '/qr_code/image') !== false);
    
    if ($isImageEndpoint) {
        header('Content-Type: text/plain');
        // Only show detailed error in development
        $errorMsg = "Error generating QR code";
        if (isset($config['app']['debug']) && $config['app']['debug']) {
            $errorMsg .= ": " . htmlspecialchars($e->getMessage()) . " in " . htmlspecialchars($e->getFile()) . ":" . $e->getLine();
        }
        echo $errorMsg;
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'An error occurred: ' . $e->getMessage()
        ]);
    }
}

