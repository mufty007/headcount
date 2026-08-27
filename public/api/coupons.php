<?php
/**
 * Admin coupons API.
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
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\CouponService;

header('Content-Type: application/json');
$config = require HC_PROJECT_ROOT . '/config/config.php';
Database::getInstance($config['database']);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

AuthMiddleware::requireAdminOrCoordinator();
if (!AuthMiddleware::can('coupons.manage') && !AuthMiddleware::can('payments.manage')) {
    jsonResponse(['success' => false, 'message' => 'Permission denied'], 403);
}

$organizationId = (int) AuthMiddleware::getOrganizationId();
$svc = new CouponService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$input = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] ?? ($input['action'] ?? 'list');

if ($method === 'GET' && $action === 'list') {
    jsonResponse(['success' => true, 'coupons' => $svc->listForOrg($organizationId)]);
}

if ($method === 'POST') {
    CsrfMiddleware::verify($input);
    if ($action === 'delete') {
        $ok = $svc->delete((int) ($input['id'] ?? 0), $organizationId);
        jsonResponse(['success' => $ok]);
    }
    $id = !empty($input['id']) ? (int) $input['id'] : null;
    $res = $svc->save($organizationId, $input, $id);
    jsonResponse($res, empty($res['success']) ? 400 : 200);
}

jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
