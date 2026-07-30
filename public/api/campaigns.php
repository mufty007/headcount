<?php
/**
 * Email Campaigns API
 * TICKET-005: save draft, schedule, send; resolve audience; exclude unsubscribed.
 */
if (ob_get_level()) ob_end_clean();
ob_start();

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && !headers_sent()) {
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . ($err['message'] ?? 'Unknown error')]);
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
use Headcount\Helpers\Security;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Services\EmailService;
use Headcount\Services\EventSeriesHelper;

try {
    if (!AuthMiddleware::requireAdmin()) exit;
    $organizationId = AuthMiddleware::getOrganizationId();
    $config = require HC_PROJECT_ROOT . '/config/config.php';
    $db = Database::getInstance($config['database']);

    $input = [];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $token = $token ?? $input['csrf_token'] ?? null;
        if (!$token || !Security::verifyCSRFToken($token)) {
            jsonResponse(['success' => false, 'message' => 'Invalid CSRF token'], 403);
            exit;
        }
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $input['action'] ?? $_GET['action'] ?? '';
    $resolveEventMergeData = function (int $eventId) use ($db, $organizationId): array {
        if ($eventId <= 0) {
            return [];
        }
        $event = $db->queryOne(
            "SELECT id, title, event_date, start_time, location, description FROM events WHERE id = ? AND organization_id = ?",
            [$eventId, $organizationId]
        );
        if (!$event) {
            return [];
        }
        $eventDate = !empty($event['event_date']) ? date('F j, Y', strtotime($event['event_date'])) : '';
        $eventDay = !empty($event['event_date']) ? date('l', strtotime($event['event_date'])) : '';
        return [
            'event_name' => $event['title'] ?? '',
            'event_date' => $eventDate,
            'event_day' => $eventDay,
            'event_day_name' => $eventDay,
            'event_time' => !empty($event['start_time']) ? date('g:i A', strtotime($event['start_time'])) : '',
            'event_location' => $event['location'] ?? '',
            'location' => $event['location'] ?? '',
            'event_description' => $event['description'] ?? '',
        ];
    };

    if ($method === 'GET' && $action === 'list') {
        $campaigns = $db->query(
            "SELECT id, name, subject, status, scheduled_at, sent_at, created_at, audience_type FROM email_campaigns WHERE organization_id = ? ORDER BY created_at DESC",
            [$organizationId]
        );
        foreach ($campaigns as &$c) {
            $cid = (int) $c['id'];
            $stats = $db->queryOne(
                "SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent FROM email_logs WHERE campaign_id = ?",
                [$cid]
            );
            $c['recipient_count'] = (int) ($stats['total'] ?? 0);
            $c['sent_count'] = (int) ($stats['sent'] ?? 0);
            $events = $db->query(
                "SELECT event_type, COUNT(*) AS cnt FROM email_campaign_events WHERE campaign_id = ? GROUP BY event_type",
                [$cid]
            );
            $c['opened'] = 0;
            $c['clicked'] = 0;
            $c['bounced'] = 0;
            $c['unsubscribed'] = 0;
            foreach ($events as $e) {
                if ($e['event_type'] === 'opened') $c['opened'] = (int) $e['cnt'];
                if ($e['event_type'] === 'clicked') $c['clicked'] = (int) $e['cnt'];
                if ($e['event_type'] === 'bounced') $c['bounced'] = (int) $e['cnt'];
                if ($e['event_type'] === 'unsubscribed') $c['unsubscribed'] = (int) $e['cnt'];
            }
            $c['open_rate'] = $c['sent_count'] > 0 ? round(100 * $c['opened'] / $c['sent_count'], 1) : 0;
            $c['click_rate'] = $c['sent_count'] > 0 ? round(100 * $c['clicked'] / $c['sent_count'], 1) : 0;
        }
        unset($c);
        jsonResponse(['success' => true, 'campaigns' => $campaigns]);
        exit;
    }

    if ($method === 'GET' && $action === 'get') {
        $id = (int) ($input['id'] ?? $_GET['id'] ?? 0);
        if ($id < 1) {
            jsonResponse(['success' => false, 'message' => 'Campaign ID required'], 400);
            exit;
        }
        $row = $db->queryOne("SELECT * FROM email_campaigns WHERE id = ? AND organization_id = ?", [$id, $organizationId]);
        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Campaign not found'], 404);
            exit;
        }
        if (!empty($row['audience_config'])) $row['audience_config'] = json_decode($row['audience_config'], true);
        jsonResponse(['success' => true, 'campaign' => $row]);
        exit;
    }

    if ($method === 'GET' && $action === 'detail') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id < 1) {
            jsonResponse(['success' => false, 'message' => 'Campaign ID required'], 400);
            exit;
        }
        $row = $db->queryOne("SELECT * FROM email_campaigns WHERE id = ? AND organization_id = ?", [$id, $organizationId]);
        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Campaign not found'], 404);
            exit;
        }
        $recipients = $db->query(
            "SELECT el.id, el.recipient_email, el.recipient_user_id, el.status, el.sent_at,
                    (SELECT COUNT(*) FROM email_campaign_events e WHERE e.email_log_id = el.id AND e.event_type = 'opened') AS opened,
                    (SELECT COUNT(*) FROM email_campaign_events e WHERE e.email_log_id = el.id AND e.event_type = 'clicked') AS clicked,
                    (SELECT COUNT(*) FROM email_campaign_events e WHERE e.email_log_id = el.id AND e.event_type = 'bounced') AS bounced,
                    (SELECT COUNT(*) FROM email_campaign_events e WHERE e.email_log_id = el.id AND e.event_type = 'unsubscribed') AS unsubscribed
             FROM email_logs el WHERE el.campaign_id = ? ORDER BY el.id",
            [$id]
        );
        $row['recipients'] = $recipients;
        if (!empty($row['audience_config'])) $row['audience_config'] = json_decode($row['audience_config'], true);
        jsonResponse(['success' => true, 'campaign' => $row]);
        exit;
    }

    if ($method === 'POST' && $action === 'cancel_scheduled') {
        $id = (int) ($input['id'] ?? 0);
        if ($id < 1) {
            jsonResponse(['success' => false, 'message' => 'Campaign ID required'], 400);
            exit;
        }
        $row = $db->queryOne("SELECT id FROM email_campaigns WHERE id = ? AND organization_id = ? AND status = 'scheduled'", [$id, $organizationId]);
        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Campaign not found or not scheduled'], 404);
            exit;
        }
        $db->update('email_campaigns', $id, ['status' => 'draft', 'scheduled_at' => null]);
        jsonResponse(['success' => true]);
        exit;
    }

    if ($method === 'POST' && $action === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        if ($id < 1) {
            jsonResponse(['success' => false, 'message' => 'Campaign ID required'], 400);
            exit;
        }
        $row = $db->queryOne("SELECT id FROM email_campaigns WHERE id = ? AND organization_id = ? AND status IN ('draft','scheduled')", [$id, $organizationId]);
        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Campaign not found or already sent'], 404);
            exit;
        }
        $db->query("DELETE FROM email_campaign_events WHERE campaign_id = ?", [$id]);
        $db->query("UPDATE email_logs SET campaign_id = NULL WHERE campaign_id = ?", [$id]);
        $db->delete('email_campaigns', $id, 'id', false);
        jsonResponse(['success' => true]);
        exit;
    }

    if ($method === 'POST' && $action === 'duplicate') {
        $id = (int) ($input['id'] ?? 0);
        if ($id < 1) {
            jsonResponse(['success' => false, 'message' => 'Campaign ID required'], 400);
            exit;
        }
        $row = $db->queryOne("SELECT * FROM email_campaigns WHERE id = ? AND organization_id = ?", [$id, $organizationId]);
        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Campaign not found'], 404);
            exit;
        }
        unset($row['id'], $row['created_at'], $row['updated_at']);
        $row['organization_id'] = $organizationId;
        $row['name'] = ($row['name'] ?? $row['subject']) . ' (Copy)';
        $row['subject'] = $row['subject'] . ' (Copy)';
        $row['status'] = 'draft';
        $row['scheduled_at'] = null;
        $row['sent_at'] = null;
        $row['created_by'] = $_SESSION['user_id'] ?? null;
        $row['body_html'] = $row['body_html'] ?? '';
        $row['design_json'] = $row['design_json'] ?? null;
        $newId = $db->insert('email_campaigns', $row);
        jsonResponse(['success' => true, 'campaign_id' => $newId]);
        exit;
    }

    if ($method !== 'POST' || !in_array($action, ['save_draft', 'schedule', 'send', 'count_recipients'], true)) {
        jsonResponse(['success' => false, 'message' => 'Invalid request'], 400);
        exit;
    }

    $subject = trim((string) ($input['subject'] ?? ''));
    $bodyHtml = $input['body_html'] ?? '';
    $designJson = $input['design_json'] ?? null;
    $audienceType = $input['audience_type'] ?? 'all_members';
    $audienceConfig = $input['audience_config'] ?? '{}';
    $scheduledAt = $action === 'schedule' ? ($input['scheduled_at'] ?? null) : null;
    $campaignId = isset($input['id']) ? (int) $input['id'] : null;

    if ($subject === '' && $action !== 'count_recipients') {
        jsonResponse(['success' => false, 'message' => 'Subject is required'], 400);
        exit;
    }

    $audienceConfigDecoded = is_string($audienceConfig) ? json_decode($audienceConfig, true) : $audienceConfig;
    $audienceConfigDecoded = is_array($audienceConfigDecoded) ? $audienceConfigDecoded : [];

    // Defensively infer audience type from config in case stale frontend state posts wrong audience_type.
    $cfgEventId = (int) ($audienceConfigDecoded['event_id'] ?? 0);
    $cfgEventUserId = (int) ($audienceConfigDecoded['event_user_id'] ?? 0);
    $cfgUserId = (int) ($audienceConfigDecoded['user_id'] ?? 0);
    $cfgGroupId = (int) ($audienceConfigDecoded['group_id'] ?? 0);
    $cfgTagId = (int) ($audienceConfigDecoded['tag_id'] ?? 0);
    $cfgGender = strtolower(trim((string) ($audienceConfigDecoded['gender'] ?? '')));
    $cfgManualEmails = $audienceConfigDecoded['manual_emails'] ?? [];
    if ($cfgEventId > 0 && $cfgEventUserId > 0) {
        $audienceType = 'event_member';
    } elseif ($cfgEventId > 0 && $audienceType !== 'event_member') {
        $audienceType = 'event';
    } elseif ($cfgUserId > 0 && $audienceType === 'all_members') {
        $audienceType = 'single_member';
    } elseif ($cfgGroupId > 0 && $audienceType === 'all_members') {
        $audienceType = 'segment';
    } elseif ($cfgTagId > 0 && $audienceType === 'all_members') {
        $audienceType = 'tag';
    } elseif ($cfgGender !== '' && $audienceType === 'all_members') {
        $audienceType = 'gender';
    } elseif (is_array($cfgManualEmails) && count(array_filter(array_map('trim', $cfgManualEmails))) > 0 && $audienceType === 'all_members') {
        $audienceType = 'manual';
    }

    // Count how many people this audience selection will actually reach (mirrors
    // the `send` resolution + unsubscribe exclusion) so the UI can preview it.
    if ($action === 'count_recipients') {
        $unsub = [];
        foreach ($db->query("SELECT LOWER(email) AS email FROM email_unsubscribes WHERE organization_id = ?", [$organizationId]) as $r) {
            if (!empty($r['email'])) { $unsub[$r['email']] = true; }
        }
        $count = 0;
        if ($audienceType === 'all_members') {
            $rows = $db->query("SELECT email FROM users WHERE organization_id = ? AND role = 'member' AND status = 'active' AND email IS NOT NULL AND email != ''", [$organizationId]);
            foreach ($rows as $r) { if (empty($unsub[strtolower($r['email'])])) { $count++; } }
        } elseif ($audienceType === 'event') {
            $eid = (int) ($audienceConfigDecoded['event_id'] ?? 0);
            if ($eid > 0) {
                $src = EventSeriesHelper::getRsvpSourceEventId($db, $eid);
                $ids = array_values(array_unique(array_column($db->query("SELECT user_id FROM rsvps WHERE event_id = ? AND status = 'yes'", [$src]), 'user_id')));
                if (!empty($ids)) {
                    $ph = implode(',', array_fill(0, count($ids), '?'));
                    $rows = $db->query("SELECT email FROM users WHERE id IN ($ph) AND organization_id = ? AND email IS NOT NULL AND email != ''", array_merge($ids, [$organizationId]));
                    foreach ($rows as $r) { if (empty($unsub[strtolower($r['email'])])) { $count++; } }
                }
            }
        } elseif ($audienceType === 'event_member' || $audienceType === 'single_member') {
            $uid = $audienceType === 'event_member'
                ? (int) ($audienceConfigDecoded['event_user_id'] ?? 0)
                : (int) ($audienceConfigDecoded['user_id'] ?? 0);
            if ($uid > 0) {
                $row = $db->queryOne("SELECT email FROM users WHERE id = ? AND organization_id = ? AND email IS NOT NULL AND email != ''", [$uid, $organizationId]);
                if ($row && empty($unsub[strtolower($row['email'])])) { $count = 1; }
            }
        } elseif ($audienceType === 'manual') {
            $emails = $audienceConfigDecoded['manual_emails'] ?? [];
            $seen = [];
            if (is_array($emails)) {
                foreach ($emails as $e) {
                    $e = strtolower(trim((string) $e));
                    if ($e === '' || !filter_var($e, FILTER_VALIDATE_EMAIL) || isset($seen[$e]) || !empty($unsub[$e])) { continue; }
                    $seen[$e] = true; $count++;
                }
            }
        } elseif ($audienceType === 'segment') {
            $gid = (int) ($audienceConfigDecoded['group_id'] ?? 0);
            if ($gid > 0) {
                $rows = $db->query("SELECT u.email FROM users u INNER JOIN group_members gm ON gm.user_id = u.id WHERE gm.group_id = ? AND u.organization_id = ? AND u.email IS NOT NULL AND u.email != ''", [$gid, $organizationId]);
                foreach ($rows as $r) { if (empty($unsub[strtolower($r['email'])])) { $count++; } }
            }
        } elseif ($audienceType === 'tag') {
            $tid = (int) ($audienceConfigDecoded['tag_id'] ?? 0);
            if ($tid > 0) {
                $rows = $db->query(
                    "SELECT u.email FROM users u INNER JOIN member_tags mt ON mt.user_id = u.id WHERE mt.tag_id = ? AND u.organization_id = ? AND u.role = 'member' AND u.status = 'active' AND u.email IS NOT NULL AND u.email != ''",
                    [$tid, $organizationId]
                );
                foreach ($rows as $r) { if (empty($unsub[strtolower($r['email'])])) { $count++; } }
            }
        } elseif ($audienceType === 'gender') {
            $g = strtolower(trim((string) ($audienceConfigDecoded['gender'] ?? '')));
            if ($g === 'unassigned') {
                $rows = $db->query(
                    "SELECT email FROM users WHERE organization_id = ? AND role = 'member' AND status = 'active' AND email IS NOT NULL AND email != '' AND (gender IS NULL OR TRIM(COALESCE(gender, '')) = '' OR LOWER(TRIM(gender)) IN ('unspecified', 'unknown', 'none'))",
                    [$organizationId]
                );
            } elseif (in_array($g, ['male', 'female', 'other'], true)) {
                $rows = $db->query(
                    "SELECT email FROM users WHERE organization_id = ? AND role = 'member' AND status = 'active' AND email IS NOT NULL AND email != '' AND gender = ?",
                    [$organizationId, $g]
                );
            } else {
                $rows = [];
            }
            foreach ($rows as $r) { if (empty($unsub[strtolower($r['email'])])) { $count++; } }
        }
        jsonResponse(['success' => true, 'count' => $count, 'audience_type' => $audienceType]);
        exit;
    }

    if ($action === 'save_draft') {
        $data = [
            'organization_id' => $organizationId,
            'name' => $subject,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'design_json' => $designJson,
            'status' => 'draft',
            'scheduled_at' => null,
            'sent_at' => null,
            'audience_type' => $audienceType,
            'audience_config' => json_encode($audienceConfigDecoded),
            'created_by' => $_SESSION['user_id'] ?? null,
        ];
        if ($campaignId) {
            $existing = $db->queryOne("SELECT id FROM email_campaigns WHERE id = ? AND organization_id = ?", [$campaignId, $organizationId]);
            if ($existing) {
                $db->update('email_campaigns', $campaignId, [
                    'name' => $data['name'],
                    'subject' => $data['subject'],
                    'body_html' => $data['body_html'],
                    'design_json' => $data['design_json'],
                    'audience_type' => $data['audience_type'],
                    'audience_config' => $data['audience_config'],
                ]);
                jsonResponse(['success' => true, 'campaign_id' => $campaignId]);
                exit;
            }
        }
        $id = $db->insert('email_campaigns', $data);
        jsonResponse(['success' => true, 'campaign_id' => $id]);
        exit;
    }

    if ($action === 'schedule') {
        if (empty($scheduledAt)) {
            jsonResponse(['success' => false, 'message' => 'Scheduled time is required'], 400);
            exit;
        }
        $data = [
            'organization_id' => $organizationId,
            'name' => $subject,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'design_json' => $designJson,
            'status' => 'scheduled',
            'scheduled_at' => date('Y-m-d H:i:s', strtotime($scheduledAt)),
            'sent_at' => null,
            'audience_type' => $audienceType,
            'audience_config' => json_encode($audienceConfigDecoded),
            'created_by' => $_SESSION['user_id'] ?? null,
        ];
        if ($campaignId) {
            $existing = $db->queryOne("SELECT id FROM email_campaigns WHERE id = ? AND organization_id = ? AND status = 'draft'", [$campaignId, $organizationId]);
            if ($existing) {
                $db->update('email_campaigns', $campaignId, [
                    'name' => $data['name'], 'subject' => $data['subject'], 'body_html' => $data['body_html'],
                    'design_json' => $data['design_json'], 'status' => 'scheduled', 'scheduled_at' => $data['scheduled_at'],
                    'audience_type' => $data['audience_type'], 'audience_config' => $data['audience_config'],
                ]);
                jsonResponse(['success' => true, 'campaign_id' => $campaignId]);
                exit;
            }
        }
        $id = $db->insert('email_campaigns', $data);
        jsonResponse(['success' => true, 'campaign_id' => $id]);
        exit;
    }

    // action === 'send'
    $unsubscribed = [];
    $unsubRows = $db->query("SELECT email FROM email_unsubscribes WHERE organization_id = ?", [$organizationId]);
    foreach ($unsubRows as $r) $unsubscribed[$r['email']] = true;

    $recipients = [];
    if ($audienceType === 'all_members') {
        $rows = $db->query(
            "SELECT id, email, first_name, last_name, phone FROM users WHERE organization_id = ? AND role = 'member' AND status = 'active' AND email IS NOT NULL AND email != ''",
            [$organizationId]
        );
        foreach ($rows as $r) {
            if (empty($unsubscribed[strtolower($r['email'])])) $recipients[] = $r;
        }
    } elseif ($audienceType === 'event') {
        $eventId = (int) ($audienceConfigDecoded['event_id'] ?? 0);
        if ($eventId < 1) {
            jsonResponse(['success' => false, 'message' => 'Event is required'], 400);
            exit;
        }
        $rsvpSourceEventId = EventSeriesHelper::getRsvpSourceEventId($db, $eventId);
        $rsvps = $db->query("SELECT user_id FROM rsvps WHERE event_id = ? AND status = 'yes'", [$rsvpSourceEventId]);
        $userIds = array_values(array_unique(array_column($rsvps, 'user_id')));
        if (empty($userIds)) {
            jsonResponse(['success' => false, 'message' => 'No attendees found for this event'], 400);
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $rows = $db->query(
            "SELECT id, email, first_name, last_name, phone FROM users WHERE id IN ($placeholders) AND organization_id = ? AND email IS NOT NULL AND email != ''",
            array_merge($userIds, [$organizationId])
        );
        foreach ($rows as $r) {
            if (empty($unsubscribed[strtolower($r['email'])])) $recipients[] = $r;
        }
    } elseif ($audienceType === 'event_member') {
        $eventId = (int) ($audienceConfigDecoded['event_id'] ?? 0);
        $eventUserId = (int) ($audienceConfigDecoded['event_user_id'] ?? 0);
        if ($eventId < 1 || $eventUserId < 1) {
            jsonResponse(['success' => false, 'message' => 'Event and member are required'], 400);
            exit;
        }
        $rsvpSourceEventId = EventSeriesHelper::getRsvpSourceEventId($db, $eventId);
        $isAttendee = $db->queryOne(
            "SELECT id FROM rsvps WHERE event_id = ? AND user_id = ? AND status = 'yes' LIMIT 1",
            [$rsvpSourceEventId, $eventUserId]
        );
        if (!$isAttendee) {
            jsonResponse(['success' => false, 'message' => 'Selected member is not an RSVP yes attendee for this event'], 400);
            exit;
        }
        $row = $db->queryOne(
            "SELECT id, email, first_name, last_name, phone FROM users WHERE id = ? AND organization_id = ? AND email IS NOT NULL AND email != ''",
            [$eventUserId, $organizationId]
        );
        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Member not found'], 400);
            exit;
        }
        if (!empty($unsubscribed[strtolower($row['email'])])) {
            jsonResponse(['success' => false, 'message' => 'Selected member has unsubscribed from emails'], 400);
            exit;
        }
        $recipients[] = $row;
    } elseif ($audienceType === 'manual') {
        $emails = $audienceConfigDecoded['manual_emails'] ?? [];
        foreach ($emails as $email) {
            $email = trim($email);
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            if (!empty($unsubscribed[strtolower($email)])) continue;
            $recipients[] = ['id' => null, 'email' => $email, 'first_name' => '', 'last_name' => '', 'phone' => ''];
        }
    } elseif ($audienceType === 'segment') {
        $groupId = (int) ($audienceConfigDecoded['group_id'] ?? 0);
        if ($groupId < 1) {
            jsonResponse(['success' => false, 'message' => 'Group is required'], 400);
            exit;
        }
        $rows = $db->query(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.phone FROM users u INNER JOIN group_members gm ON gm.user_id = u.id WHERE gm.group_id = ? AND u.organization_id = ? AND u.email IS NOT NULL AND u.email != ''",
            [$groupId, $organizationId]
        );
        foreach ($rows as $r) {
            if (empty($unsubscribed[strtolower($r['email'])])) $recipients[] = $r;
        }
    } elseif ($audienceType === 'tag') {
        $tagId = (int) ($audienceConfigDecoded['tag_id'] ?? 0);
        if ($tagId < 1) {
            jsonResponse(['success' => false, 'message' => 'Tag is required'], 400);
            exit;
        }
        $rows = $db->query(
            "SELECT u.id, u.email, u.first_name, u.last_name, u.phone FROM users u INNER JOIN member_tags mt ON mt.user_id = u.id WHERE mt.tag_id = ? AND u.organization_id = ? AND u.role = 'member' AND u.status = 'active' AND u.email IS NOT NULL AND u.email != ''",
            [$tagId, $organizationId]
        );
        foreach ($rows as $r) {
            if (empty($unsubscribed[strtolower($r['email'])])) $recipients[] = $r;
        }
    } elseif ($audienceType === 'gender') {
        $gender = strtolower(trim((string) ($audienceConfigDecoded['gender'] ?? '')));
        if ($gender === '') {
            jsonResponse(['success' => false, 'message' => 'Gender is required'], 400);
            exit;
        }
        if ($gender === 'unassigned') {
            $rows = $db->query(
                "SELECT id, email, first_name, last_name, phone FROM users WHERE organization_id = ? AND role = 'member' AND status = 'active' AND email IS NOT NULL AND email != '' AND (gender IS NULL OR TRIM(COALESCE(gender, '')) = '' OR LOWER(TRIM(gender)) IN ('unspecified', 'unknown', 'none'))",
                [$organizationId]
            );
        } elseif (in_array($gender, ['male', 'female', 'other'], true)) {
            $rows = $db->query(
                "SELECT id, email, first_name, last_name, phone FROM users WHERE organization_id = ? AND role = 'member' AND status = 'active' AND email IS NOT NULL AND email != '' AND gender = ?",
                [$organizationId, $gender]
            );
        } else {
            jsonResponse(['success' => false, 'message' => 'Invalid gender'], 400);
            exit;
        }
        foreach ($rows as $r) {
            if (empty($unsubscribed[strtolower($r['email'])])) $recipients[] = $r;
        }
    } elseif ($audienceType === 'single_member') {
        $userId = (int) ($audienceConfigDecoded['user_id'] ?? 0);
        if ($userId < 1) {
            jsonResponse(['success' => false, 'message' => 'Member is required'], 400);
            exit;
        }
        $row = $db->queryOne(
            "SELECT id, email, first_name, last_name, phone FROM users WHERE id = ? AND organization_id = ? AND email IS NOT NULL AND email != ''",
            [$userId, $organizationId]
        );
        if (!$row) {
            jsonResponse(['success' => false, 'message' => 'Member not found'], 400);
            exit;
        }
        if (empty($unsubscribed[strtolower($row['email'])])) {
            $recipients[] = $row;
        }
    }

    if (empty($recipients)) {
        jsonResponse(['success' => false, 'message' => 'No recipients found or all are unsubscribed'], 400);
        exit;
    }

    $org = $db->queryOne(
        "SELECT name, logo_path, smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?",
        [$organizationId]
    );
    $smtpApiKey = null;
    if (!empty($org['smtp_api_key'])) {
        $decoded = base64_decode($org['smtp_api_key'], true);
        if ($decoded !== false && $decoded !== '') $smtpApiKey = $decoded;
    }
    if (($smtpApiKey === null || $smtpApiKey === '') && !empty($org['smtp_api_key_encrypted'])) {
        $encKey = $config['security']['encryption_key'] ?? null;
        if ($encKey) {
            $decrypted = Security::decrypt($org['smtp_api_key_encrypted'], $encKey);
            if ($decrypted !== false && $decrypted !== '') $smtpApiKey = $decrypted;
        }
    }
    if (($smtpApiKey === null || $smtpApiKey === '') && !empty($config['smtp2go']['api_key'])) {
        $smtpApiKey = $config['smtp2go']['api_key'];
    }
    if (empty($smtpApiKey) || empty($org['smtp_from_email'])) {
        jsonResponse(['success' => false, 'message' => 'Email service is not configured. Configure SMTP in Settings → Email.'], 503);
        exit;
    }
    $smtpConfig = [
        'api_key' => $smtpApiKey,
        'from_email' => $org['smtp_from_email'],
        'from_name' => $org['smtp_from_name'] ?? '',
        'reply_to' => $org['smtp_reply_to'] ?? $org['smtp_from_email'],
    ];
    $appUrl = rtrim($config['app']['url'] ?? '', '/');
    $logoUrl = null;
    if (!empty($org['logo_path'])) {
        if (strpos($org['logo_path'], 'http') === 0) {
            $logoUrl = $org['logo_path'];
        } else {
            $logoRelative = ltrim($org['logo_path'], '/');
            if (strpos($logoRelative, 'public/') !== 0) {
                $logoRelative = 'public/' . $logoRelative;
            }
            $logoUrl = $appUrl . '/' . $logoRelative;
        }
    }
    $signingKey = $config['security']['encryption_key'] ?? '';

    $campaignRow = [
        'organization_id' => $organizationId,
        'name' => $subject,
        'subject' => $subject,
        'body_html' => $bodyHtml,
        'design_json' => $designJson,
        'status' => 'sending',
        'scheduled_at' => null,
        'sent_at' => null,
        'audience_type' => $audienceType,
        'audience_config' => json_encode($audienceConfigDecoded),
        'created_by' => $_SESSION['user_id'] ?? null,
    ];
    if ($campaignId) {
        $ex = $db->queryOne(
            "SELECT id, status FROM email_campaigns WHERE id = ? AND organization_id = ?",
            [$campaignId, $organizationId]
        );
        if ($ex) {
            $existingStatus = (string) ($ex['status'] ?? '');
            if (in_array($existingStatus, ['sent', 'sending'], true)) {
                jsonResponse([
                    'success' => false,
                    'message' => $existingStatus === 'sent'
                        ? 'This campaign was already sent. Create a new campaign to send again.'
                        : 'This campaign is already sending. Please wait for it to finish.',
                ], 409);
                exit;
            }
            if (!in_array($existingStatus, ['draft', 'scheduled'], true)) {
                jsonResponse(['success' => false, 'message' => 'This campaign cannot be sent in its current state.'], 409);
                exit;
            }
            $db->update('email_campaigns', $campaignId, [
                'name' => $campaignRow['name'], 'subject' => $campaignRow['subject'], 'body_html' => $campaignRow['body_html'],
                'design_json' => $campaignRow['design_json'], 'status' => 'sending', 'scheduled_at' => null,
                'audience_type' => $campaignRow['audience_type'], 'audience_config' => $campaignRow['audience_config'],
            ]);
        } else {
            $campaignId = $db->insert('email_campaigns', $campaignRow);
        }
    } else {
        $campaignId = $db->insert('email_campaigns', $campaignRow);
    }

    // Deduplicate recipients by email so nobody gets the campaign twice in one send
    $uniqueRecipients = [];
    $seenEmails = [];
    foreach ($recipients as $rec) {
        $emailKey = strtolower(trim((string) ($rec['email'] ?? '')));
        if ($emailKey === '' || isset($seenEmails[$emailKey])) {
            continue;
        }
        $seenEmails[$emailKey] = true;
        $uniqueRecipients[] = $rec;
    }
    $recipients = $uniqueRecipients;

    $emailService = new EmailService($smtpConfig);
    $sent = 0;
    $failed = 0;
    $eventMergeData = [];
    $eventIdForMerge = (int) ($audienceConfigDecoded['event_id'] ?? 0);
    if ($eventIdForMerge > 0) {
        $eventMergeData = $resolveEventMergeData($eventIdForMerge);
        if (in_array($audienceType, ['event', 'event_member'], true) && empty($eventMergeData)) {
            $db->update('email_campaigns', $campaignId, ['status' => 'draft']);
            jsonResponse(['success' => false, 'message' => 'Selected event context could not be loaded for placeholder merge'], 400);
            exit;
        }
    }
    $actorUserId = $_SESSION['user_id'] ?? null;
    foreach ($recipients as $rec) {
        try {
            $mergeData = array_merge($rec, $eventMergeData, ['organization_name' => $org['name'] ?? '']);
            $mergedSubject = $emailService->processTemplate($subject, $mergeData);
            $body = $emailService->processTemplate($bodyHtml, $mergeData);
            $unsubUrl = generateUnsubscribeUrl($organizationId, $rec['email'], $campaignId, $appUrl, $signingKey);
            $body = appendUnsubscribeFooter($body, $unsubUrl, $org['name'] ?? '');
            $body = str_replace('{{unsubscribe_link}}', $unsubUrl, $body);
            $body = str_replace('{unsubscribe_link}', $unsubUrl, $body);
            $body = wrapEmailWithBranding($body, $logoUrl, $org['name'] ?? '');
            $result = $emailService->sendEmail($rec['email'], $mergedSubject, $body, $organizationId, [
                'template' => 'custom',
                'event_id' => $eventIdForMerge > 0 ? $eventIdForMerge : null,
                'user_id' => $rec['id'] ?? null,
                'actor_user_id' => $actorUserId,
                'campaign_id' => $campaignId,
            ]);
            if (!empty($result['success'])) {
                $sent++;
            } else {
                $failed++;
            }
        } catch (\Throwable $e) {
            $failed++;
            error_log('Campaign recipient send error: ' . $e->getMessage());
        }
    }

    try {
        $db->update('email_campaigns', $campaignId, [
            'status' => 'sent',
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        error_log('Campaign status update after send failed: ' . $e->getMessage());
    }

    jsonResponse([
        'success' => true,
        'campaign_id' => $campaignId,
        'message' => "Success! Your emails have been sent. $sent delivered" . ($failed > 0 ? ", $failed failed" : '') . '.',
        'sent' => $sent,
        'failed' => $failed,
    ]);
} catch (\Throwable $e) {
    error_log("Campaigns API error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
}
