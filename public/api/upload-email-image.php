<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

set_error_handler(function($errno, $errstr, $errfile, $errline) {
    if (error_reporting() === 0) return false;
    error_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
    if (!headers_sent()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Server error occurred']);
        exit;
    }
    return false;
});

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Core\RateLimiter;
use Headcount\Helpers\Security;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;

if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

header('Content-Type: application/json');

if (!AuthMiddleware::requireAdminOrCoordinator()) {
    exit;
}

$organizationId = AuthMiddleware::getOrganizationId();
$orgId = (int)$organizationId;
if ($orgId < 1) {
    jsonResponse(['success' => false, 'message' => 'Invalid organization'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Method not allowed'], 405);
}

$token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
if (!$token || !Security::verifyCSRFToken($token)) {
    jsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 403);
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['success' => false, 'message' => 'No file uploaded or upload error'], 400);
}

$file = $_FILES['image'];
$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = ['png', 'jpg', 'jpeg', 'gif', 'webp'];
if (!in_array($ext, $allowed, true)) {
    jsonResponse(['success' => false, 'message' => 'Allowed formats: PNG, JPG, GIF, WebP'], 400);
}

$finfo = new \finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);
$allowedMimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];
if (!in_array($mime, $allowedMimes, true)) {
    jsonResponse(['success' => false, 'message' => 'Invalid file type'], 400);
}

$userId = (int) (AuthMiddleware::getUserId() ?? 0);
if ($userId > 0) {
    RateLimiter::checkUploadRateLimit($userId, 20, 3600);
}

$maxBytes = 5 * 1024 * 1024; // 5MB for email images
if ($file['size'] > $maxBytes) {
    jsonResponse(['success' => false, 'message' => 'File must be 5MB or less'], 400);
}

$config = require HC_PROJECT_ROOT . '/config/config.php';
$baseDir = defined('PUBLIC_PATH') ? PUBLIC_PATH : (__DIR__ . '/..');
$uploadDir = $baseDir . '/uploads/organizations/' . $orgId . '/email';
if (!is_dir($uploadDir)) {
    if (!@mkdir($uploadDir, 0755, true)) {
        jsonResponse(['success' => false, 'message' => 'Could not create upload directory'], 500);
    }
}

$filename = uniqid('img_', true) . '.' . $ext;
$path = $uploadDir . '/' . $filename;
if (!move_uploaded_file($file['tmp_name'], $path)) {
    jsonResponse(['success' => false, 'message' => 'Failed to save file'], 500);
}

$appUrl = rtrim($config['app']['url'] ?? '', '/');
$url = $appUrl . '/public/uploads/organizations/' . $orgId . '/email/' . $filename;

jsonResponse(['success' => true, 'url' => $url]);
