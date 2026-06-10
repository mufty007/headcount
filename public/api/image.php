<?php
/**
 * Image Serving Endpoint
 * Serves uploaded images securely from the uploads directory
 * 
 * Access via: /public/api/image.php?path=event-banners/filename.jpg
 */

// Prevent JSON header from being set
if (!defined('SKIP_JSON_HEADER')) {
    define('SKIP_JSON_HEADER', true);
}

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

// Get image path from query string
$imagePath = $_GET['path'] ?? '';

if (empty($imagePath)) {
    http_response_code(400);
    die('Image path required');
}

// Load config
$config = require __DIR__ . '/../../config/config.php';
$uploadPath = $config['uploads']['upload_path'] ?? __DIR__ . '/../../uploads/';

// Normalize upload path (ensure it's absolute)
if (!empty($uploadPath) && !preg_match('/^[A-Za-z]:\\\\|^\//', $uploadPath)) {
    // Relative path, make it absolute
    $uploadPath = realpath(__DIR__ . '/../../' . ltrim($uploadPath, '/\\')) ?: $uploadPath;
}

// Define PUBLIC_PATH if not already defined
if (!defined('PUBLIC_PATH')) {
    define('PUBLIC_PATH', dirname(__DIR__));
}

// Sanitize path to prevent directory traversal
$imagePath = str_replace('..', '', $imagePath);
$imagePath = ltrim($imagePath, '/\\');

// Construct full file path
$fullPath = rtrim($uploadPath, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $imagePath);

// Verify file exists and is within upload directory
$realUploadPath = realpath($uploadPath);
if (!$realUploadPath) {
    // Try to create the directory if it doesn't exist
    if (!is_dir($uploadPath)) {
        @mkdir($uploadPath, 0755, true);
    }
    $realUploadPath = realpath($uploadPath);
}

// Check if file exists (use both realpath and file_exists for better compatibility)
$fileExists = file_exists($fullPath) && is_file($fullPath);
$realFilePath = $fileExists ? realpath($fullPath) : false;

if (!$fileExists || !$realFilePath) {
    // If file doesn't exist, try to serve default banner if it's an event banner
    if (strpos($imagePath, 'event-banners/') === 0) {
        // Try multiple possible locations for default banner
        $possiblePaths = [
            __DIR__ . '/../images/default-event-banner.png',
            __DIR__ . '/../../public/images/default-event-banner.png',
            PUBLIC_PATH . '/images/default-event-banner.png'
        ];
        
        foreach ($possiblePaths as $defaultBanner) {
            if (file_exists($defaultBanner) && is_file($defaultBanner)) {
                header('Content-Type: image/png');
                header('Cache-Control: public, max-age=3600');
                readfile($defaultBanner);
                exit;
            }
        }
        
        // If no default banner found, generate a simple placeholder (1x1 transparent PNG)
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=3600');
        echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
        exit;
    }
    if (strpos($imagePath, 'facility-images/') === 0) {
        header('Content-Type: image/svg+xml');
        header('Cache-Control: public, max-age=3600');
        echo '<?xml version="1.0" encoding="UTF-8"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="800" height="450" viewBox="0 0 800 450">'
            . '<defs><linearGradient id="g" x1="0%" y1="0%" x2="100%" y2="100%">'
            . '<stop offset="0%" stop-color="#EEF2FF"/><stop offset="100%" stop-color="#F1F5F9"/>'
            . '</linearGradient></defs>'
            . '<rect width="800" height="450" fill="url(#g)"/>'
            . '<g transform="translate(400 225)" fill="none" stroke="#A5B4FC" stroke-width="6" stroke-linecap="round" stroke-linejoin="round">'
            . '<path d="M-60 -50 V50 H60 V-50 Z M-40 -50 V-70 H40 V-50"/>'
            . '<path d="M-30 10 H-10 V30 H-10 M10 10 H30 V30 H10"/>'
            . '</g></svg>';
        exit;
    }
    http_response_code(404);
    die('Image not found');
}

if (!$realUploadPath || strpos($realFilePath, $realUploadPath) !== 0) {
    http_response_code(404);
    die('Image not found');
}

// Verify it's an image file
$allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

if (!in_array($extension, $allowedExtensions) || !is_file($fullPath)) {
    http_response_code(404);
    die('Invalid image file');
}

// Set appropriate content type
$mimeTypes = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'webp' => 'image/webp'
];

header('Content-Type: ' . ($mimeTypes[$extension] ?? 'image/jpeg'));
header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');

// Output image
readfile($fullPath);
exit;
