<?php
/**
 * Programs listing has moved into the combined catalog.
 * Preserve search when redirecting.
 */
require_once __DIR__ . '/bootstrap.php';

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
if (preg_match('#/portal(/.*)?$#', $requestPath)) {
    $pos = strpos($requestPath, '/portal');
    $baseUrlPath = $pos !== false ? substr($requestPath, 0, $pos) : '';
} else {
    $baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
}
$baseUrlPath = rtrim((string) $baseUrlPath, '/');

$q = ['type' => 'program'];
$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
if ($search !== '') {
    $q['search'] = $search;
}
if (!empty($_GET['category'])) {
    $q['category'] = (string) $_GET['category'];
}

$target = $baseUrlPath . '/portal/events.php?' . http_build_query($q);
header('Location: ' . $target, true, 302);
exit;
