<?php

/**
 * Search Members API Endpoint
 * GET /api/search-members.php?q={query}&event_id={id}
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
use Headcount\Helpers\OrgTimeZone;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Core\Cache;
use Headcount\Services\EventInviteService;

header('Content-Type: application/json');

try {
    $config = require HC_PROJECT_ROOT . '/config/config.php';
    Database::getInstance($config['database']);

    // Require admin authentication
    AuthMiddleware::requireAdminOrCoordinator();

    $organizationId = AuthMiddleware::getOrganizationId();
    $db = Database::getInstance();
    if (!$db) {
        jsonResponse(['success' => false, 'message' => 'Database not initialized'], 500);
    }

    $query = trim(get('q', ''));
    $eventId = get('event_id');
    $purpose = strtolower(trim((string) get('purpose', 'checkin')));
    $forInvite = ($purpose === 'invite');

    if (strlen($query) < 2) {
        jsonResponse(['success' => true, 'members' => []], 200);
    }

    if (!$eventId) {
        jsonResponse(['success' => false, 'message' => 'Event ID required'], 400);
    }

    // Verify event belongs to organization (need event_date to match checkin-rsvps session scope)
    $event = $db->queryOne("SELECT id, event_date FROM events WHERE id = :id AND organization_id = :org_id", [
        'id' => $eventId,
        'org_id' => $organizationId
    ]);

    if (!$event) {
        jsonResponse(['success' => false, 'message' => 'Event not found'], 404);
    }

    // Invite search only needs basic member identity fields — skip check-in joins/FULLTEXT.
    if ($forInvite) {
        $inviteStorageId = 0;
        $inviteExcludeSql = '';
        $inviteSvc = new EventInviteService();
        if ($inviteSvc->tableExists()) {
            $inviteStorageId = EventInviteService::inviteStorageEventId($db, (int) $eventId);
            if ($inviteStorageId > 0) {
                $inviteExcludeSql = ' AND NOT EXISTS (SELECT 1 FROM event_invites ei WHERE ei.event_id = :invite_storage_event_id AND ei.user_id = u.id)';
            }
        }

        $cacheKey = 'member_search_' . md5($organizationId . '_' . $eventId . '_invite_' . $query);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            jsonResponse(['success' => true, 'members' => $cached], 200);
        }

        $searchTerm = '%' . $query . '%';
        $sql = "SELECT u.id, u.first_name, u.last_name, u.email
                FROM users u
                WHERE u.organization_id = :org_id
                AND u.role = 'member'
                AND u.status = 'active'
                {$inviteExcludeSql}
                AND (
                    u.first_name LIKE :search1 OR
                    u.last_name LIKE :search2 OR
                    u.email LIKE :search3 OR
                    u.phone LIKE :search4 OR
                    CONCAT(u.first_name, ' ', u.last_name) LIKE :search5
                )
                ORDER BY u.first_name ASC, u.last_name ASC
                LIMIT 10";
        $params = [
            'org_id' => $organizationId,
            'search1' => $searchTerm,
            'search2' => $searchTerm,
            'search3' => $searchTerm,
            'search4' => $searchTerm,
            'search5' => $searchTerm,
        ];
        if ($inviteStorageId > 0) {
            $params['invite_storage_event_id'] = $inviteStorageId;
        }
        $members = $db->query($sql, $params);
        Cache::set($cacheKey, $members, 60);
        jsonResponse(['success' => true, 'members' => $members], 200);
    }

    $eventDateYmd = substr((string) ($event['event_date'] ?? ''), 0, 10);

    $orgTzRow = $db->queryOne("SELECT timezone FROM organizations WHERE id = :id", ['id' => $organizationId]);
    $orgTimezone = OrgTimeZone::resolve(is_array($orgTzRow) ? ($orgTzRow['timezone'] ?? null) : null);

    $passwordFilterSql = " AND u.password_hash IS NOT NULL AND TRIM(u.password_hash) <> ''";

    // Check cache first (1 minute TTL)
    $cacheKey = 'member_search_' . md5($organizationId . '_' . $eventId . '_' . $purpose . '_' . $query);
    $cached = Cache::get($cacheKey);
    if ($cached !== null) {
        jsonResponse(['success' => true, 'members' => $cached], 200);
    }

    // Try FULLTEXT search first (faster for longer queries)
    $members = [];
    $useFulltext = strlen($query) >= 3;

    if ($useFulltext) {
        // Use FULLTEXT search if available
        $fulltextQuery = preg_replace('/[^\w\s]/', '', $query); // Remove special chars for FULLTEXT
        $fulltextQuery = trim($fulltextQuery);

        if (!empty($fulltextQuery)) {
            try {
                $rsvpCols = $db->query("SHOW COLUMNS FROM rsvps");
                $guestCountCol = in_array('guest_count', array_column($rsvpCols, 'Field')) ? ', r.guest_count' : '';

                $sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.password_hash,
                        a.checked_in_at,
                        CASE WHEN a.checked_in_at IS NOT NULL AND DATE(a.checked_in_at) = :event_date_ft THEN 1 ELSE 0 END as checked_in,
                        r.status as rsvp_status{$guestCountCol},
                        MATCH(u.first_name, u.last_name, u.email, u.phone) AGAINST(:search IN BOOLEAN MODE) as relevance
                        FROM users u
                        LEFT JOIN attendance a ON u.id = a.user_id AND a.event_id = :event_id AND DATE(a.checked_in_at) = :event_date_a
                        LEFT JOIN rsvps r ON u.id = r.user_id AND r.event_id = :event_id2
                        WHERE u.organization_id = :org_id
                        AND u.role = 'member'
                        AND u.status = 'active'
                        {$passwordFilterSql}
                        AND MATCH(u.first_name, u.last_name, u.email, u.phone) AGAINST(:search IN BOOLEAN MODE)
                        ORDER BY checked_in ASC, relevance DESC, u.first_name ASC
                        LIMIT 10";

                $searchTerm = '+' . str_replace(' ', ' +', $fulltextQuery) . '*';
                $members = $db->query($sql, [
                    'event_id' => $eventId,
                    'event_id2' => $eventId,
                    'event_date_ft' => $eventDateYmd,
                    'event_date_a' => $eventDateYmd,
                    'org_id' => $organizationId,
                    'search' => $searchTerm,
                ]);
            } catch (\Exception $e) {
                // FULLTEXT index might not exist, fall back to LIKE
                $useFulltext = false;
            }
        }
    }

    // Fall back to LIKE search if FULLTEXT failed or query too short
    if (empty($members) || !$useFulltext) {
        $rsvpCols = $db->query("SHOW COLUMNS FROM rsvps");
        $guestCountCol = in_array('guest_count', array_column($rsvpCols, 'Field')) ? ', r.guest_count' : '';

        $sql = "SELECT u.id, u.first_name, u.last_name, u.email, u.phone, u.password_hash,
                a.checked_in_at,
                CASE WHEN a.checked_in_at IS NOT NULL AND DATE(a.checked_in_at) = :event_date_ft THEN 1 ELSE 0 END as checked_in,
                r.status as rsvp_status{$guestCountCol}
                FROM users u
                LEFT JOIN attendance a ON u.id = a.user_id AND a.event_id = :event_id AND DATE(a.checked_in_at) = :event_date_a
                LEFT JOIN rsvps r ON u.id = r.user_id AND r.event_id = :event_id2
                WHERE u.organization_id = :org_id
                AND u.role = 'member'
                AND u.status = 'active'
                {$passwordFilterSql}
                AND (
                    u.first_name LIKE :search1 OR
                    u.last_name LIKE :search2 OR
                    u.email LIKE :search3 OR
                    u.phone LIKE :search4 OR
                    CONCAT(u.first_name, ' ', u.last_name) LIKE :search5
                )
                ORDER BY checked_in ASC, u.first_name ASC
                LIMIT 10";

        $searchTerm = "%$query%";
        $members = $db->query($sql, [
            'event_id' => $eventId,
            'event_id2' => $eventId,
            'event_date_ft' => $eventDateYmd,
            'event_date_a' => $eventDateYmd,
            'org_id' => $organizationId,
            'search1' => $searchTerm,
            'search2' => $searchTerm,
            'search3' => $searchTerm,
            'search4' => $searchTerm,
            'search5' => $searchTerm,
        ]);
    }

    // Add payment info for this event (cash/stripe) if payments table has payment_method
    $paymentCols = $db->query("SHOW COLUMNS FROM payments LIKE 'payment_method'");
    $hasPaymentMethod = !empty($paymentCols);
    $paymentsByUser = [];
    if ($hasPaymentMethod && !empty($members)) {
        $userIds = array_column($members, 'id');
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $payments = $db->query(
            "SELECT user_id, id as payment_id, amount as payment_amount, payment_method FROM payments WHERE event_id = ? AND user_id IN ($placeholders) AND status = 'paid'",
            array_merge([$eventId], $userIds)
        );
        foreach ($payments as $p) {
            $paymentsByUser[(int) $p['user_id']] = [
                'payment_id' => (int) $p['payment_id'],
                'payment_amount' => (float) $p['payment_amount'],
                'payment_method' => $p['payment_method'] ?? 'stripe',
            ];
        }
    }

    // Format member data
    foreach ($members as &$member) {
        $member['checked_in'] = (bool) $member['checked_in'];
        if ($member['checked_in_at']) {
            $member['checked_in_time'] = formatAttendanceLocalTimeForOrganization($member['checked_in_at'], $orgTimezone);
        }

        // Add user type and guest info
        $member['user_type'] = !empty($member['password_hash']) ? 'Member' : 'Guest';
        $member['guest_count'] = (int) ($member['guest_count'] ?? 0);

        if ($hasPaymentMethod && isset($paymentsByUser[(int) $member['id']])) {
            $member['payment_id'] = $paymentsByUser[(int) $member['id']]['payment_id'];
            $member['payment_amount'] = $paymentsByUser[(int) $member['id']]['payment_amount'];
            $member['payment_method'] = $paymentsByUser[(int) $member['id']]['payment_method'];
        } else {
            $member['payment_id'] = null;
            $member['payment_amount'] = null;
            $member['payment_method'] = null;
        }

        // Clean up
        unset($member['relevance']);
        unset($member['password_hash']);
    }
    unset($member);

    // Cache results for 1 minute
    Cache::set($cacheKey, $members, 60);

    jsonResponse(['success' => true, 'members' => $members], 200);
} catch (\Throwable $e) {
    error_log('search-members.php error: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Search failed. Please try again.'], 500);
}
