<?php

/**
 * Portal Social API
 * Handles social features (attendees list, sharing, invitations)
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Helpers\Utilities;
use Headcount\Services\PortalEmailService;

// Load config
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuration not found']);
    exit;
}

$config = require $configFile;

// Initialize database
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database initialization failed']);
    exit;
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    Security::configureSession();
    session_start();
}

// Set JSON header
header('Content-Type: application/json');

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($requestUri, PHP_URL_PATH);

// Extract action/ID from path
$pathSegments = explode('/', trim($path, '/'));
$action = $pathSegments[count($pathSegments) - 1] ?? '';
$eventId = null;
if (is_numeric($action) && count($pathSegments) >= 2 && $pathSegments[count($pathSegments) - 2] === 'attendees') {
    $eventId = (int)$action;
    $action = 'attendees';
}

// Get input data (when routed from index.php, $data is already set and php://input is consumed)
if (!isset($input)) {
    $input = json_decode(@file_get_contents('php://input'), true) ?? [];
}
if (!isset($data)) {
    $data = array_merge($_POST, $input);
}

$db = Database::getInstance();

try {
    // GET /api/portal/social/attendees/{id} - Get attendees list for event (public or authenticated)
    if ($action === 'attendees' && $eventId && $method === 'GET') {
        // Check if event allows showing attendees (for now, we'll show if user is logged in)
        // In future, add a field to events table: show_attendees BOOLEAN
        
        $attendees = $db->query(
            "SELECT u.id, u.first_name, u.last_name, u.email
             FROM attendance a
             JOIN users u ON a.user_id = u.id
             WHERE a.event_id = :event_id
             AND u.status = 'active'
             ORDER BY a.checked_in_at DESC
             LIMIT 100",
            ['event_id' => $eventId]
        );

        // Only return names, not emails (privacy)
        $attendees = array_map(function($attendee) {
            return [
                'id' => $attendee['id'],
                'name' => trim($attendee['first_name'] . ' ' . $attendee['last_name'])
            ];
        }, $attendees);

        echo json_encode([
            'success' => true,
            'attendees' => $attendees,
            'count' => count($attendees)
        ]);
        exit;
    }

    // POST /api/portal/social/share - Share event on social media (no-op, returns share URLs)
    if ($action === 'share' && $method === 'POST') {
        $eventId = $data['event_id'] ?? 0;
        
        if (empty($eventId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Event ID required']);
            exit;
        }

        $event = $db->queryOne(
            "SELECT * FROM events WHERE id = :id AND status = 'published'",
            ['id' => $eventId]
        );

        if (!$event) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Event not found']);
            exit;
        }

        $baseUrl = getBaseUrl();
        $eventUrl = function_exists('headcount_event_portal_url')
            ? headcount_event_portal_url($config, (int) $eventId)
            : ($baseUrl . '/portal/event-details.php?id=' . $eventId);
        $title = urlencode(Utilities::decodeHtmlEntities($event['title'] ?? ''));
        $descPlain = Utilities::decodeHtmlEntities($event['description'] ?? '');
        $description = urlencode(substr($descPlain, 0, 200));

        $shareUrls = [
            'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . urlencode($eventUrl),
            'twitter' => 'https://twitter.com/intent/tweet?text=' . $title . '&url=' . urlencode($eventUrl),
            'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . urlencode($eventUrl),
            'email' => 'mailto:?subject=' . $title . '&body=' . $description . '%20' . urlencode($eventUrl)
        ];

        echo json_encode([
            'success' => true,
            'share_urls' => $shareUrls,
            'event_url' => $eventUrl
        ]);
        exit;
    }

    // POST /api/portal/social/invite - Invite friends via email (requires auth)
    if ($action === 'invite' && $method === 'POST') {
        // Verify CSRF
        CsrfMiddleware::verify($data);
        
        // Require authentication
        PortalAuthMiddleware::requireAuth();
        $memberId = PortalAuthMiddleware::getMemberId();
        
        $eventId = (int) ($data['event_id'] ?? 0);
        $emails = $data['emails'] ?? [];

        if (empty($eventId)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Event ID required']);
            exit;
        }

        if (empty($emails) || !is_array($emails)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Email addresses required']);
            exit;
        }

        // Cap invites per request
        $emails = array_values(array_unique(array_filter(array_map('trim', $emails))));
        if (count($emails) > 20) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'You can invite at most 20 people at a time']);
            exit;
        }

        $event = $db->queryOne(
            "SELECT * FROM events WHERE id = :id AND status = 'published'",
            ['id' => $eventId]
        );

        if (!$event) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Event not found']);
            exit;
        }

        $member = $db->queryOne(
            "SELECT id, first_name, last_name, email, organization_id FROM users WHERE id = :id",
            ['id' => $memberId]
        );

        $organizationId = (int) ($event['organization_id'] ?? $member['organization_id'] ?? 0);
        $emailService = createSocialInviteEmailService($db, $config, $organizationId);
        if (!$emailService) {
            http_response_code(503);
            echo json_encode([
                'success' => false,
                'message' => 'Email is not configured. Invitations could not be sent.',
                'emails_sent' => 0,
                'emails_failed' => count($emails),
            ]);
            exit;
        }

        $inviterName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
        if ($inviterName === '') {
            $inviterName = 'A member';
        }
        $eventUrl = headcount_event_portal_url($config, $eventId);
        $eventDate = !empty($event['event_date']) ? date('F j, Y', strtotime($event['event_date'])) : '';
        $eventTime = !empty($event['start_time']) ? date('g:i A', strtotime($event['start_time'])) : '';
        $eventLocation = (string) ($event['location'] ?? '');
        $eventName = Utilities::decodeHtmlEntities($event['title'] ?? 'Event');

        $templatePath = HC_PROJECT_ROOT . '/templates/portal/social-invite.html';
        $bodyTemplate = file_exists($templatePath)
            ? file_get_contents($templatePath)
            : '<p><strong>{inviter_name}</strong> invited you to <strong>{event_name}</strong>.</p><p><a href="{event_url}">View Event</a></p>';

        $sent = 0;
        $failed = [];
        foreach ($emails as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $failed[] = $email;
                continue;
            }

            $body = str_replace(
                ['{inviter_name}', '{event_name}', '{event_date}', '{event_time}', '{event_location}', '{event_url}'],
                [
                    htmlspecialchars($inviterName, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($eventName, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($eventDate, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($eventTime, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($eventLocation, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($eventUrl, ENT_QUOTES, 'UTF-8'),
                ],
                $bodyTemplate
            );

            $result = $emailService->sendEmail(
                $email,
                $inviterName . ' invited you to ' . $eventName,
                $body,
                $organizationId ?: null,
                [
                    'email_type' => 'social_invite',
                    'event_id' => $eventId,
                    'user_id' => $memberId,
                ]
            );

            if (!empty($result['success'])) {
                $sent++;
            } else {
                $failed[] = $email;
            }
        }

        if ($sent === 0) {
            http_response_code(502);
            echo json_encode([
                'success' => false,
                'message' => 'No invitations were sent',
                'emails_sent' => 0,
                'emails_failed' => count($failed),
                'failed_emails' => $failed,
            ]);
            exit;
        }

        echo json_encode([
            'success' => true,
            'message' => $failed
                ? "Sent {$sent} invitation(s); " . count($failed) . " failed"
                : "Sent {$sent} invitation(s)",
            'emails_sent' => $sent,
            'emails_failed' => count($failed),
            'failed_emails' => $failed,
        ]);
        exit;
    }

    // 404 - Endpoint not found
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Endpoint not found']);
    
} catch (\Exception $e) {
    http_response_code(500);
    error_log("Portal social API error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred: ' . $e->getMessage()
    ]);
}

/**
 * Get base URL (config-aware for cron/CLI)
 */
function getBaseUrl()
{
    global $config;
    if (!empty($config) && is_array($config)) {
        return headcount_portal_base_url($config);
    }

    $configFile = HC_PROJECT_ROOT . '/config/config.php';
    if (file_exists($configFile)) {
        return headcount_portal_base_url(require $configFile);
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host;
}

/**
 * Build PortalEmailService from org SMTP or global config.
 *
 * @return PortalEmailService|null
 */
function createSocialInviteEmailService($db, array $config, int $organizationId)
{
    if ($organizationId > 0) {
        try {
            $org = $db->queryOne(
                "SELECT smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?",
                [$organizationId]
            );
            if ($org && !empty($org['smtp_from_email'])) {
                $apiKey = null;
                if (!empty($org['smtp_api_key'])) {
                    $decoded = base64_decode($org['smtp_api_key'], true);
                    $apiKey = ($decoded !== false && $decoded !== '') ? $decoded : null;
                }
                if (($apiKey === null || $apiKey === '') && !empty($org['smtp_api_key_encrypted']) && !empty($config['security']['encryption_key'])) {
                    $apiKey = Security::decrypt($org['smtp_api_key_encrypted'], $config['security']['encryption_key']);
                }
                if (!empty($apiKey)) {
                    return new PortalEmailService([
                        'api_key' => $apiKey,
                        'from_email' => $org['smtp_from_email'],
                        'from_name' => $org['smtp_from_name'] ?? null,
                        'reply_to' => $org['smtp_reply_to'] ?? $org['smtp_from_email'],
                    ]);
                }
            }
        } catch (\Throwable $e) {
            error_log('social invite org SMTP: ' . $e->getMessage());
        }
    }

    if (!empty($config['smtp2go']['api_key']) && !empty($config['smtp2go']['from_email'])) {
        return new PortalEmailService($config['smtp2go']);
    }

    return null;
}
