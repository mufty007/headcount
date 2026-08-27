<?php
/**
 * Mark PWA install guide as seen (portal or admin).
 */
if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;

header('Content-Type: application/json');
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!is_file($configFile)) {
    jsonResponse(['success' => false], 500);
}
$config = require $configFile;
Database::getInstance($config['database']);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
CsrfMiddleware::verify($input);

$userId = 0;
if (!empty($_SESSION['user_id']) && in_array($_SESSION['role'] ?? '', ['admin', 'coordinator', 'presenter'], true)) {
    AuthMiddleware::check();
    $userId = (int) AuthMiddleware::getUserId();
} elseif (PortalAuthMiddleware::isAuthenticated()) {
    $userId = (int) PortalAuthMiddleware::getMemberId();
}

if ($userId <= 0) {
    jsonResponse(['success' => false, 'message' => 'Not signed in'], 401);
}

$db = Database::getInstance();
if ($db->hasColumn('users', 'pwa_guide_seen_at')) {
    $db->execute('UPDATE users SET pwa_guide_seen_at = NOW() WHERE id = :id', ['id' => $userId]);
}
jsonResponse(['success' => true]);
