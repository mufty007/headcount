<?php
/**
 * Campaigns list moved into Email Center (Send email tab). Old bookmarks redirect here.
 */
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Middleware\AuthMiddleware;

AuthMiddleware::requireCan('campaigns.send');

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
$basePath = preg_replace('#/admin/.*$#', '', $requestPath);
$basePath = rtrim($basePath, '/');
$adminBase = $basePath . '/admin';

header('Location: ' . $adminBase . '/?page=email-campaigns', true, 302);
exit;
