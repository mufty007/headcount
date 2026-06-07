<?php
/**
 * Admin layout variables – single source of truth for base paths and nav URLs.
 * Include this before header/footer (or let header include it) so all admin pages
 * get consistent $basePath, $adminBase, $assetsBase, and $navUrls.
 */

if (isset($adminBase) && isset($navUrls)) {
    $router = rtrim($adminBase, '/') . '/';
    $defaults = [
        'dashboard' => $router . '?page=dashboard',
        'events' => $router . '?page=events',
        'programs' => $router . '?page=programs',
        'program-attendance' => $router . '?page=program-attendance',
        'facilities' => $router . '?page=facilities',
        'facility-edit' => $router . '?page=facility-edit',
        'facility-details' => $router . '?page=facility-details',
        'facility-bookings' => $router . '?page=facility-bookings',
        'members' => $router . '?page=members',
        'checkin' => $router . '?page=checkin',
        'reports' => $router . '?page=reports',
        'payment-transfers' => $router . '?page=payment-transfers',
        'notifications' => $router . '?page=notifications',
        'activity-log' => $router . '?page=activity-log',
        'refund-requests' => $router . '?page=refund-requests',
        'email-templates' => $router . '?page=email-templates',
        'campaigns' => $router . '?page=email-campaigns',
        'settings' => $router . '?page=settings',
        'health' => $router . '?page=health',
        'member-add' => $router . '?page=member-add',
        'member-edit' => $router . '?page=member-edit',
        'event-create' => $router . '?page=event-create',
        'event-edit' => $router . '?page=event-edit',
    ];
    foreach ($defaults as $k => $v) {
        if (!isset($navUrls[$k])) {
            $navUrls[$k] = $v;
        }
    }
    if (!isset($currentPage)) {
        $currentPage = $_GET['page'] ?? 'dashboard';
    }
    return;
}

$requestUri = $_SERVER['REQUEST_URI'] ?? '/admin/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);
// Detect basePath by looking for the directory containing 'admin' or 'public'
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';

if (strpos($scriptName, '/public/admin/') !== false) {
    $basePath = substr($scriptName, 0, strpos($scriptName, '/public/admin/'));
} else if (strpos($scriptName, '/admin/') !== false) {
    $basePath = substr($scriptName, 0, strpos($scriptName, '/admin/'));
} else if (strpos($scriptName, '/public/') !== false) {
    $basePath = substr($scriptName, 0, strpos($scriptName, '/public/'));
} else {
    // Fallback if none of the above are in the URL
    $basePath = dirname($scriptName);
}
$basePath = rtrim($basePath, '/\\');


if (!empty($basePath) && $basePath[0] !== '/') {
    $basePath = '/' . $basePath;
}

if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}

$adminRouter = rtrim($adminBase, '/') . '/';

if (!isset($assetsBase)) {
    $assetsBase = strpos($basePath, '/public') !== false
        ? $basePath . '/assets/'
        : $basePath . '/public/assets/';
}

if (!isset($navUrls)) {
    $navUrls = [
        'dashboard'         => $adminRouter . '?page=dashboard',
        'events'             => $adminRouter . '?page=events',
        'programs'           => $adminRouter . '?page=programs',
        'program-attendance' => $adminRouter . '?page=program-attendance',
        'facilities'         => $adminRouter . '?page=facilities',
        'facility-edit'      => $adminRouter . '?page=facility-edit',
        'facility-details'   => $adminRouter . '?page=facility-details',
        'facility-bookings'  => $adminRouter . '?page=facility-bookings',
        'members'            => $adminRouter . '?page=members',
        'checkin'            => $adminRouter . '?page=checkin',
        'reports'            => $adminRouter . '?page=reports',
        'payment-transfers'  => $adminRouter . '?page=payment-transfers',
        'notifications'      => $adminRouter . '?page=notifications',
        'activity-log'       => $adminRouter . '?page=activity-log',
        'refund-requests'    => $adminRouter . '?page=refund-requests',
        'email-templates'    => $adminRouter . '?page=email-templates',
        'campaigns'          => $adminRouter . '?page=email-campaigns',
        'settings'           => $adminRouter . '?page=settings',
        'health'             => $adminRouter . '?page=health',
        'member-add'         => $adminRouter . '?page=member-add',
        'member-edit'        => $adminRouter . '?page=member-edit',
        'event-create'       => $adminRouter . '?page=event-create',
        'event-edit'         => $adminRouter . '?page=event-edit',
    ];
}

if (!isset($currentPage)) {
    $currentPage = $_GET['page'] ?? 'dashboard';
}
