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
        'main-calendar' => $router . '?page=main-calendar',
        'events' => $router . '?page=events',
        'events-calendar' => $router . '?page=events-calendar',
        'programs' => $router . '?page=programs',
        'program-attendance' => $router . '?page=program-attendance',
        'program-requests' => $router . '?page=program-requests',
        'program-request-form' => $router . '?page=program-request-form',
        'program-request-details' => $router . '?page=program-request-details',
        'facilities' => $router . '?page=facilities',
        'facility-edit' => $router . '?page=facility-edit',
        'facility-details' => $router . '?page=facility-details',
        'facility-bookings' => $router . '?page=facility-bookings',
        'facility-bookings-calendar' => $router . '?page=facility-bookings-calendar',
        'facility-booking-waiver' => $router . '?page=facility-booking-waiver',
        'members' => $router . '?page=members',
        'checkin' => $router . '?page=checkin',
        'reports' => $router . '?page=reports',
        'payment-transfers' => $router . '?page=payment-transfers',
        'coupons' => $router . '?page=coupons',
        'notifications' => $router . '?page=notifications',
        'activity-log' => $router . '?page=activity-log',
        'refund-requests' => $router . '?page=refund-requests',
        'email-templates' => $router . '?page=email-templates',
        'campaigns' => $router . '?page=email-campaigns',
        'settings' => $router . '?page=settings',
        'documentation' => $router . '?page=documentation',
        'profile' => $router . '?page=profile',
        'health' => $router . '?page=health',
        'member-add' => $router . '?page=member-add',
        'member-edit' => $router . '?page=member-edit',
        'event-create' => $router . '?page=event-create',
        'event-edit' => $router . '?page=event-edit',
        'event-requests' => $router . '?page=event-requests',
        'event-request-form' => $router . '?page=event-request-form',
        'event-request-details' => $router . '?page=event-request-details',
        'event-checklist' => $router . '?page=event-checklist',
        'my-tasks' => $router . '?page=my-tasks',
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
        'main-calendar'     => $adminRouter . '?page=main-calendar',
        'events'             => $adminRouter . '?page=events',
        'events-calendar'    => $adminRouter . '?page=events-calendar',
        'programs'           => $adminRouter . '?page=programs',
        'program-attendance' => $adminRouter . '?page=program-attendance',
        'program-requests' => $adminRouter . '?page=program-requests',
        'program-request-form' => $adminRouter . '?page=program-request-form',
        'program-request-details' => $adminRouter . '?page=program-request-details',
        'facilities'         => $adminRouter . '?page=facilities',
        'facility-edit'      => $adminRouter . '?page=facility-edit',
        'facility-details'   => $adminRouter . '?page=facility-details',
        'facility-bookings'  => $adminRouter . '?page=facility-bookings',
        'facility-bookings-calendar' => $adminRouter . '?page=facility-bookings-calendar',
        'facility-booking-waiver' => $adminRouter . '?page=facility-booking-waiver',
        'members'            => $adminRouter . '?page=members',
        'checkin'            => $adminRouter . '?page=checkin',
        'reports'            => $adminRouter . '?page=reports',
        'payment-transfers'  => $adminRouter . '?page=payment-transfers',
        'coupons'            => $adminRouter . '?page=coupons',
        'notifications'      => $adminRouter . '?page=notifications',
        'activity-log'       => $adminRouter . '?page=activity-log',
        'refund-requests'    => $adminRouter . '?page=refund-requests',
        'email-templates'    => $adminRouter . '?page=email-templates',
        'campaigns'          => $adminRouter . '?page=email-campaigns',
        'settings'           => $adminRouter . '?page=settings',
        'documentation'      => $adminRouter . '?page=documentation',
        'profile'            => $adminRouter . '?page=profile',
        'health'             => $adminRouter . '?page=health',
        'member-add'         => $adminRouter . '?page=member-add',
        'member-edit'        => $adminRouter . '?page=member-edit',
        'event-create'       => $adminRouter . '?page=event-create',
        'event-edit'         => $adminRouter . '?page=event-edit',
        'event-requests'     => $adminRouter . '?page=event-requests',
        'event-request-form' => $adminRouter . '?page=event-request-form',
        'event-request-details' => $adminRouter . '?page=event-request-details',
        'event-checklist'    => $adminRouter . '?page=event-checklist',
        'my-tasks'           => $adminRouter . '?page=my-tasks',
    ];
}

if (!isset($currentPage)) {
    $currentPage = $_GET['page'] ?? 'dashboard';
}
