<?php
/**
 * Send scheduled email campaigns
 * TICKET-005: Run periodically (e.g. every 5–15 min). Picks campaigns with status=scheduled and scheduled_at <= NOW().
 * Example cron: every 15 minutes.
 **/

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Services\EmailService;

$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    error_log("Send scheduled campaigns: config not found");
    headcount_cron_exit(1);
}

$config = require $configFile;

try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    error_log("Send scheduled campaigns: DB init failed: " . $e->getMessage());
    headcount_cron_exit(1);
}

$db = Database::getInstance();
$now = date('Y-m-d H:i:s');
$resolveEventMergeData = function (int $eventId, int $organizationId) use ($db): array {
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
$rows = $db->query(
    "SELECT * FROM email_campaigns WHERE status = 'scheduled' AND scheduled_at IS NOT NULL AND scheduled_at <= ?",
    [$now]
);

foreach ($rows as $campaign) {
    $organizationId = (int) $campaign['organization_id'];
    $campaignId = (int) $campaign['id'];
    $subject = $campaign['subject'];
    $bodyHtml = $campaign['body_html'];
    $audienceType = $campaign['audience_type'];
    $audienceConfig = json_decode($campaign['audience_config'] ?? '{}', true);
    if (!is_array($audienceConfig)) $audienceConfig = [];

    $db->update('email_campaigns', $campaignId, ['status' => 'sending']);

    $unsubscribed = [];
    $unsubRows = $db->query("SELECT email FROM email_unsubscribes WHERE organization_id = ?", [$organizationId]);
    foreach ($unsubRows as $r) $unsubscribed[strtolower($r['email'])] = true;

    $recipients = [];
    if ($audienceType === 'all_members') {
        $list = $db->query("SELECT id, email, first_name, last_name, phone FROM users WHERE organization_id = ? AND role = 'member' AND status = 'active' AND email IS NOT NULL AND email != ''", [$organizationId]);
        foreach ($list as $r) { if (empty($unsubscribed[strtolower($r['email'])])) $recipients[] = $r; }
    } elseif ($audienceType === 'event') {
        $eventId = (int) ($audienceConfig['event_id'] ?? 0);
        if ($eventId > 0) {
            $rsvps = $db->query("SELECT user_id FROM rsvps WHERE event_id = ? AND status = 'yes'", [$eventId]);
            $userIds = array_values(array_unique(array_column($rsvps, 'user_id')));
            if (!empty($userIds)) {
                $ph = implode(',', array_fill(0, count($userIds), '?'));
                $list = $db->query("SELECT id, email, first_name, last_name, phone FROM users WHERE id IN ($ph) AND organization_id = ? AND email IS NOT NULL AND email != ''", array_merge($userIds, [$organizationId]));
                foreach ($list as $r) { if (empty($unsubscribed[strtolower($r['email'])])) $recipients[] = $r; }
            }
        }
    } elseif ($audienceType === 'event_member') {
        $eventId = (int) ($audienceConfig['event_id'] ?? 0);
        $eventUserId = (int) ($audienceConfig['event_user_id'] ?? 0);
        if ($eventId > 0 && $eventUserId > 0) {
            $isAttendee = $db->queryOne(
                "SELECT id FROM rsvps WHERE event_id = ? AND user_id = ? AND status = 'yes' LIMIT 1",
                [$eventId, $eventUserId]
            );
            if ($isAttendee) {
                $row = $db->queryOne(
                    "SELECT id, email, first_name, last_name, phone FROM users WHERE id = ? AND organization_id = ? AND email IS NOT NULL AND email != ''",
                    [$eventUserId, $organizationId]
                );
                if ($row && empty($unsubscribed[strtolower($row['email'])])) {
                    $recipients[] = $row;
                }
            }
        }
    } elseif ($audienceType === 'manual') {
        $emails = $audienceConfig['manual_emails'] ?? [];
        foreach ($emails as $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && empty($unsubscribed[strtolower($email)])) {
                $recipients[] = ['id' => null, 'email' => $email, 'first_name' => '', 'last_name' => '', 'phone' => ''];
            }
        }
    } elseif ($audienceType === 'segment') {
        $groupId = (int) ($audienceConfig['group_id'] ?? 0);
        if ($groupId > 0) {
            $list = $db->query("SELECT u.id, u.email, u.first_name, u.last_name, u.phone FROM users u INNER JOIN group_members gm ON gm.user_id = u.id WHERE gm.group_id = ? AND u.organization_id = ? AND u.email IS NOT NULL AND u.email != ''", [$groupId, $organizationId]);
            foreach ($list as $r) { if (empty($unsubscribed[strtolower($r['email'])])) $recipients[] = $r; }
        }
    }

    if (empty($recipients)) {
        $db->update('email_campaigns', $campaignId, ['status' => 'sent', 'sent_at' => $now]);
        continue;
    }

    $org = $db->queryOne("SELECT name, logo_path, smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?", [$organizationId]);
    $smtpApiKey = null;
    if (!empty($org['smtp_api_key'])) { $dec = base64_decode($org['smtp_api_key'], true); if ($dec !== false && $dec !== '') $smtpApiKey = $dec; }
    if (($smtpApiKey === null || $smtpApiKey === '') && !empty($org['smtp_api_key_encrypted'])) {
        $encKey = $config['security']['encryption_key'] ?? null;
        if ($encKey) { $dec = Security::decrypt($org['smtp_api_key_encrypted'], $encKey); if ($dec !== false && $dec !== '') $smtpApiKey = $dec; }
    }
    if (($smtpApiKey === null || $smtpApiKey === '') && !empty($config['smtp2go']['api_key'])) $smtpApiKey = $config['smtp2go']['api_key'];
    if (empty($smtpApiKey) || empty($org['smtp_from_email'])) {
        $db->update('email_campaigns', $campaignId, ['status' => 'draft']);
        continue;
    }

    $smtpConfig = ['api_key' => $smtpApiKey, 'from_email' => $org['smtp_from_email'], 'from_name' => $org['smtp_from_name'] ?? '', 'reply_to' => $org['smtp_reply_to'] ?? $org['smtp_from_email']];
    $appUrl = rtrim($config['app']['url'] ?? '', '/');
    $logoUrl = !empty($org['logo_path']) ? ((strpos($org['logo_path'], 'http') === 0) ? $org['logo_path'] : $appUrl . '/' . ltrim($org['logo_path'], '/')) : null;
    $signingKey = $config['security']['encryption_key'] ?? '';

    $emailService = new EmailService($smtpConfig);
    $eventIdForMerge = (int) ($audienceConfig['event_id'] ?? 0);
    $eventMergeData = $resolveEventMergeData($eventIdForMerge, $organizationId);
    foreach ($recipients as $rec) {
        $mergeData = array_merge($rec, $eventMergeData, ['organization_name' => $org['name'] ?? '']);
        $mergedSubject = $emailService->processTemplate($subject, $mergeData);
        $body = $emailService->processTemplate($bodyHtml, $mergeData);
        $unsubUrl = generateUnsubscribeUrl($organizationId, $rec['email'], $campaignId, $appUrl, $signingKey);
        $body = appendUnsubscribeFooter($body, $unsubUrl, $org['name'] ?? '');
        $body = str_replace(['{{unsubscribe_link}}', '{unsubscribe_link}'], $unsubUrl, $body);
        $body = wrapEmailWithBranding($body, $logoUrl, $org['name'] ?? '');
        $emailService->sendEmail($rec['email'], $mergedSubject, $body, $organizationId, [
            'template' => 'custom',
            'event_id' => $eventIdForMerge > 0 ? $eventIdForMerge : null,
            'user_id' => $rec['id'] ?? null,
            'campaign_id' => $campaignId
        ]);
    }

    $db->update('email_campaigns', $campaignId, ['status' => 'sent', 'sent_at' => $now]);
}
