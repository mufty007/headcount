<?php
/**
 * Global Quick Search API
 *
 * Powers the admin header "Quick search" box. Returns matching members and
 * events for the current organization. Results are gated by the same granular
 * permissions that control the sidebar: members appear only with members.manage,
 * events only with events.manage.
 *
 * GET /public/api/search.php?q=term[&limit=6]
 */

// Start output buffering
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json', true, 500);
        echo json_encode(['success' => false, 'message' => 'Server error']);
        error_log('Search API fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
    }
});

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

try {
    $config = require __DIR__ . '/../../config/config.php';
    Database::getInstance($config['database']);

    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/json', true);

    // Admins and coordinators only.
    AuthMiddleware::requireAdminOrCoordinator();
    $organizationId = AuthMiddleware::getOrganizationId();
    $db = Database::getInstance();

    $term = trim((string)($_GET['q'] ?? ''));
    $limit = isset($_GET['limit']) ? max(1, min(10, (int)$_GET['limit'])) : 6;

    // Need at least 2 characters to search.
    if (mb_strlen($term) < 2) {
        jsonResponse(['success' => true, 'query' => $term, 'members' => [], 'events' => []]);
        exit;
    }

    $like = '%' . $term . '%';
    $members = [];
    $events = [];

    // ---- Members (users with role = member) ----
    if (AuthMiddleware::can('members.manage')) {
        $sql = "SELECT id, first_name, last_name, email, phone
                FROM users
                WHERE organization_id = :org_id
                  AND role = 'member'
                  AND (
                      first_name LIKE :term
                      OR last_name LIKE :term
                      OR email LIKE :term
                      OR phone LIKE :term
                      OR TRIM(CONCAT(COALESCE(first_name,''), ' ', COALESCE(last_name,''))) LIKE :term
                  )
                ORDER BY first_name ASC, last_name ASC
                LIMIT " . (int)$limit;
        $rows = $db->query($sql, ['org_id' => $organizationId, 'term' => $like]);
        foreach ($rows as $r) {
            $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
            $members[] = [
                'id'       => (int)$r['id'],
                'name'     => $name !== '' ? $name : ($r['email'] ?? 'Member'),
                'subtitle' => $r['email'] ?? '',
                'page'     => 'member-details',
            ];
        }
    }

    // ---- Events ----
    if (AuthMiddleware::can('events.manage')) {
        $sql = "SELECT id, title, event_date, location, status
                FROM events
                WHERE organization_id = :org_id
                  AND (title LIKE :term OR location LIKE :term)
                ORDER BY event_date DESC
                LIMIT " . (int)$limit;
        $rows = $db->query($sql, ['org_id' => $organizationId, 'term' => $like]);
        foreach ($rows as $r) {
            $sub = [];
            if (!empty($r['event_date'])) {
                $ts = strtotime($r['event_date']);
                if ($ts) {
                    $sub[] = date('M j, Y', $ts);
                }
            }
            if (!empty($r['location'])) {
                $sub[] = $r['location'];
            }
            $events[] = [
                'id'       => (int)$r['id'],
                'name'     => $r['title'] ?? 'Event',
                'subtitle' => implode(' • ', $sub),
                'status'   => $r['status'] ?? '',
                'page'     => 'event-details',
            ];
        }
    }

    jsonResponse([
        'success' => true,
        'query'   => $term,
        'members' => $members,
        'events'  => $events,
    ]);
} catch (Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('Search API error: ' . $e->getMessage());
    header('Content-Type: application/json', true, 500);
    echo json_encode(['success' => false, 'message' => 'Search failed']);
}
