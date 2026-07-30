<?php

namespace Headcount\Services;

use Headcount\Services\EmailService;

/**
 * Portal Email Service
 * Extends EmailService for portal-specific emails
 */
class PortalEmailService extends EmailService
{
    /**
     * Normalize event text fields for email output (legacy rows may store &amp; in title/location).
     *
     * @param array<string, mixed> $event
     * @return array<string, mixed>
     */
    private function normalizeEventForEmail(array $event): array
    {
        if (\function_exists('headcount_decode_html_entities_in_event_row')) {
            headcount_decode_html_entities_in_event_row($event);
        }
        return $event;
    }

    /**
     * Build payment receipt body.
     */
    public function buildPaymentReceiptBody($event, $member, float $amount)
    {
        $event = $this->normalizeEventForEmail($event);
        $eventDate = !empty($event['event_date']) ? date('F j, Y', strtotime($event['event_date'])) : '';
        $eventTime = !empty($event['start_time']) ? date('g:i A', strtotime($event['start_time'])) : '';
        $location = (string) ($event['location'] ?? '');
        $firstName = (string) ($member['first_name'] ?? '');

        return "
            <h2>Payment received</h2>
            <p>Hi " . htmlspecialchars($firstName) . ",</p>
            <p>We received your payment for <strong>" . htmlspecialchars((string) ($event['title'] ?? 'your event')) . "</strong>.</p>
            <p><strong>Amount:</strong> $" . number_format($amount, 2) . "<br>
            <strong>Date:</strong> " . htmlspecialchars($eventDate) . "<br>
            <strong>Time:</strong> " . htmlspecialchars($eventTime) . "<br>
            <strong>Location:</strong> " . htmlspecialchars($location) . "</p>
            <p>Your registration is confirmed. We look forward to seeing you there.</p>
        ";
    }

    /**
     * Send payment receipt email.
     */
    public function sendPaymentReceipt($event, $member, float $amount)
    {
        $event = $this->normalizeEventForEmail($event);
        $subject = "Payment Received: " . ($event['title'] ?? 'Event');
        $body = $this->buildPaymentReceiptBody($event, $member, $amount);
        return $this->sendEmail(
            $member['email'],
            $subject,
            $body,
            $member['organization_id'] ?? null,
            [
                'template' => 'receipt',
                'event_id' => $event['id'] ?? null,
                'user_id' => $member['id'] ?? null
            ]
        );
    }

    /**
     * Build RSVP confirmation email body (for resend or preview).
     *
     * @param array $event Event data
     * @param array $member Member data
     * @return string HTML body
     */
    public function buildRSVPConfirmationBody($event, $member)
    {
        $event = $this->normalizeEventForEmail($event);
        $templatePath = __DIR__ . '/../../templates/portal/rsvp-confirmation.html';

        $memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
        $eventDate = date('F j, Y', strtotime($event['event_date']));
        $eventTime = !empty($event['start_time']) ? date('g:i A', strtotime($event['start_time'])) : '';
        $location = (string) ($event['location'] ?? '');
        $baseUrl = $this->getBaseUrl();
        $eventId = (int) ($event['id'] ?? 0);
        $eventUrl = $eventId > 0 ? ($baseUrl . '/portal/event-details.php?id=' . $eventId) : ($baseUrl . '/portal/events.php');
        $dashboardUrl = $baseUrl . '/portal/my-rsvps.php';
        $joinLink = (!empty($event['is_virtual']) && $location !== '') ? $location : '';
        $joinLinkBlock = $joinLink !== ''
            ? '<p style="margin: 8px 0; color: #4b5563;"><strong>Join link:</strong> <a href="' . htmlspecialchars($joinLink, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($joinLink, ENT_QUOTES, 'UTF-8') . '</a></p>'
            : '';
        $descriptionRaw = (string) ($event['description'] ?? '');
        $descriptionSafe = $descriptionRaw !== ''
            ? '<div style="margin: 16px 0; color: #4b5563;">' . htmlspecialchars(strip_tags($descriptionRaw), ENT_QUOTES, 'UTF-8') . '</div>'
            : '';

        if (file_exists($templatePath)) {
            $body = file_get_contents($templatePath);
            $body = str_replace('{first_name}', htmlspecialchars((string) ($member['first_name'] ?? ''), ENT_QUOTES, 'UTF-8'), $body);
            $body = str_replace('{full_name}', htmlspecialchars($memberName, ENT_QUOTES, 'UTF-8'), $body);
            $body = str_replace('{event_name}', htmlspecialchars((string) ($event['title'] ?? ''), ENT_QUOTES, 'UTF-8'), $body);
            $body = str_replace('{event_date}', htmlspecialchars($eventDate, ENT_QUOTES, 'UTF-8'), $body);
            $body = str_replace('{event_time}', htmlspecialchars($eventTime, ENT_QUOTES, 'UTF-8'), $body);
            $body = str_replace('{event_location}', htmlspecialchars($location, ENT_QUOTES, 'UTF-8'), $body);
            $body = str_replace('{location}', htmlspecialchars($location, ENT_QUOTES, 'UTF-8'), $body);
            $body = str_replace('{join_link}', htmlspecialchars($joinLink, ENT_QUOTES, 'UTF-8'), $body);
            $body = str_replace('{join_link_block}', $joinLinkBlock, $body);
            $body = str_replace('{event_description}', $descriptionSafe, $body);
            $body = str_replace('{event_url}', htmlspecialchars($eventUrl, ENT_QUOTES, 'UTF-8'), $body);
            $body = str_replace('{dashboard_url}', htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8'), $body);

            $calendarLink = $baseUrl . '/api/portal/calendar/event/' . $eventId . '.ics';
            $googleCalendarLink = $baseUrl . '/api/portal/calendar/google/' . $eventId;
            $body = str_replace('{calendar_link}', htmlspecialchars($calendarLink, ENT_QUOTES, 'UTF-8'), $body);
            $body = str_replace('{google_calendar_link}', htmlspecialchars($googleCalendarLink, ENT_QUOTES, 'UTF-8'), $body);
            return $body;
        }

        return "
            <h2>RSVP Confirmation</h2>
            <p>Hello " . htmlspecialchars((string) ($member['first_name'] ?? ''), ENT_QUOTES, 'UTF-8') . ",</p>
            <p>Your RSVP has been confirmed for:</p>
            <h3>" . htmlspecialchars((string) ($event['title'] ?? ''), ENT_QUOTES, 'UTF-8') . "</h3>
            <p><strong>Date:</strong> {$eventDate}</p>
            <p><strong>Time:</strong> {$eventTime}</p>
            <p><strong>Location:</strong> " . htmlspecialchars($location, ENT_QUOTES, 'UTF-8') . "</p>
            <p><a href=\"" . htmlspecialchars($eventUrl, ENT_QUOTES, 'UTF-8') . "\">View event</a> ·
               <a href=\"" . htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') . "\">Dashboard</a></p>
            <p>We look forward to seeing you there!</p>
        ";
    }

    /**
     * Send RSVP confirmation email
     * 
     * @param array $rsvp RSVP data
     * @param array $event Event data
     * @param array $member Member data
     * @return array Result
     */
    public function sendRSVPConfirmation($rsvp, $event, $member)
    {
        $event = $this->normalizeEventForEmail($event);
        $body = $this->buildRSVPConfirmationBody($event, $member);
        $subject = "RSVP Confirmed: " . ($event['title'] ?? '');

        return $this->sendEmail(
            $member['email'],
            $subject,
            $body,
            $member['organization_id'] ?? $event['organization_id'] ?? null,
            [
                'email_type' => 'rsvp_confirmation',
                'event_id' => $event['id'],
                'user_id' => $member['id']
            ]
        );
    }

    /**
     * Send guest RSVP confirmation (and optional "complete your account" link for new users)
     *
     * @param array $rsvp RSVP data
     * @param array $event Event data
     * @param array $member Member (user) data
     * @param string|null $completeAccountUrl URL to complete account (set password); if null, no CTA
     * @return array Result
     */
    public function sendGuestRSVPConfirmation($rsvp, $event, $member, $completeAccountUrl = null)
    {
        $event = $this->normalizeEventForEmail($event);
        $memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
        $eventDate = date('F j, Y', strtotime($event['event_date']));
        $eventTime = !empty($event['start_time']) ? date('g:i A', strtotime($event['start_time'])) : '';

        $body = "
            <h2>RSVP Confirmed!</h2>
            <p>Hello " . htmlspecialchars($member['first_name'] ?? '') . ",</p>
            <p>Your RSVP has been confirmed for:</p>
            <h3>" . htmlspecialchars($event['title'] ?? '') . "</h3>
            <p><strong>Date:</strong> {$eventDate}</p>
            <p><strong>Time:</strong> {$eventTime}</p>
            <p><strong>Location:</strong> " . htmlspecialchars($event['location'] ?? '') . "</p>
            <p>We look forward to seeing you there!</p>
        ";
        if ($completeAccountUrl) {
            $body .= "
            <p style=\"margin-top: 24px; padding: 16px; background: #f0f9ff; border-radius: 8px;\">
                <strong>Complete your account</strong><br>
                Set a password so you can log in to manage future RSVPs and view your event history:<br>
                <a href=\"" . htmlspecialchars($completeAccountUrl) . "\" style=\"display: inline-block; margin-top: 8px; background: #3B82F6; color: white; padding: 10px 20px; text-decoration: none; border-radius: 6px; font-weight: bold;\">Complete account</a>
            </p>
            ";
        }

        $subject = "RSVP Confirmed: " . ($event['title'] ?? 'Event');

        return $this->sendEmail(
            $member['email'],
            $subject,
            $body,
            $member['organization_id'] ?? $event['organization_id'] ?? null,
            [
                'email_type' => 'rsvp_confirmation',
                'event_id' => $event['id'],
                'user_id' => $member['id']
            ]
        );
    }

    /**
     * Notify a user they were invited to an invite-only event.
     *
     * @param array $event Published event row
     * @param array $user User row (member)
     */
    public function sendEventInviteNotification(array $event, array $user, string $eventPortalUrl, string $registerUrl, bool $needsProfile): array
    {
        $event = $this->normalizeEventForEmail($event);
        $eventDate = date('F j, Y', strtotime($event['event_date'] ?? 'now'));
        $eventTime = !empty($event['start_time']) ? date('g:i A', strtotime($event['start_time'])) : '';
        $body = '
            <h2>You\'re invited</h2>
            <p>Hello ' . htmlspecialchars($user['first_name'] ?? '') . ',</p>
            <p>You have been invited to:</p>
            <h3>' . htmlspecialchars($event['title'] ?? 'Event') . '</h3>
            <p><strong>Date:</strong> ' . htmlspecialchars($eventDate) . '</p>';
        if ($eventTime !== '') {
            $body .= '<p><strong>Time:</strong> ' . htmlspecialchars($eventTime) . '</p>';
        }
        if (!empty($event['location'])) {
            $body .= '<p><strong>Location:</strong> ' . htmlspecialchars($event['location']) . '</p>';
        }
        if ($needsProfile) {
            $body .= '
            <p style="margin-top:24px;padding:16px;background:#f0f9ff;border-radius:8px;">
                <strong>Complete your profile to RSVP</strong><br>
                This is an invite-only event. Set up your member account, then log in to reserve your spot:<br>
                <a href="' . htmlspecialchars($registerUrl) . '" style="display:inline-block;margin-top:8px;background:#3B82F6;color:white;padding:10px 20px;text-decoration:none;border-radius:6px;font-weight:bold;">Complete your profile</a>
            </p>';
        } else {
            $loginUrl = preg_replace('#/portal/register\.php.*$#', '/portal/login.php', $registerUrl);
            $body .= '
            <p style="margin-top:24px;padding:16px;background:#f0f9ff;border-radius:8px;">
                <strong>Log in to RSVP</strong><br>
                <a href="' . htmlspecialchars($loginUrl) . '" style="display:inline-block;margin-top:8px;background:#3B82F6;color:white;padding:10px 20px;text-decoration:none;border-radius:6px;font-weight:bold;">Log in</a>
            </p>';
        }
        $body .= '
            <p style="margin-top:16px;">
                <a href="' . htmlspecialchars($eventPortalUrl) . '">View event details</a>
            </p>';

        return $this->sendEmail(
            $user['email'],
            'You\'re invited: ' . ($event['title'] ?? 'Event'),
            $body,
            $event['organization_id'] ?? ($user['organization_id'] ?? null),
            [
                'email_type' => 'event_invite_notification',
                'event_id' => $event['id'] ?? null,
                'user_id' => $user['id'] ?? null,
            ]
        );
    }

    /**
     * Send RSVP cancellation email
     * 
     * @param array $rsvp RSVP data
     * @param array $event Event data
     * @param array $member Member data
     * @return array Result
     */
    public function sendRSVPCancellation($rsvp, $event, $member)
    {
        $event = $this->normalizeEventForEmail($event);
        $memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
        $eventDate = date('F j, Y', strtotime($event['event_date']));
        $eventTitle = htmlspecialchars((string) ($event['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        
        $body = "
            <h2>RSVP Cancelled</h2>
            <p>Hello {$member['first_name']},</p>
            <p>Your RSVP has been cancelled for:</p>
            <h3>{$eventTitle}</h3>
            <p><strong>Date:</strong> {$eventDate}</p>
            <p>We're sorry you won't be able to make it. We hope to see you at future events!</p>
        ";

        $subject = "RSVP Cancelled: {$event['title']}";

        return $this->sendEmail(
            $member['email'],
            $subject,
            $body,
            $member['organization_id'] ?? null,
            [
                'email_type' => 'rsvp_cancellation',
                'event_id' => $event['id'],
                'user_id' => $member['id']
            ]
        );
    }

    /**
     * Send magic link email (delegates to MagicLinkService)
     * This is kept for consistency but MagicLinkService handles it
     */
    public function sendMagicLink($email, $token, $url, $organizationId)
    {
        $templatePath = __DIR__ . '/../../templates/portal/magic-link.html';
        
        if (file_exists($templatePath)) {
            $body = file_get_contents($templatePath);
            $body = str_replace('{magic_link_url}', $url, $body);
            $body = str_replace('{expiry_minutes}', '15', $body);
        } else {
            $body = "
                <h2>Your Magic Link</h2>
                <p>Click the link below to log in to your account:</p>
                <p><a href=\"{$url}\" style=\"display:inline-block;padding:10px 20px;background:#3B82F6;color:white;text-decoration:none;border-radius:5px;\">Log In</a></p>
                <p>This link will expire in 15 minutes.</p>
            ";
        }

        $subject = "Your Magic Link Login";

        return $this->sendEmail(
            $email,
            $subject,
            $body,
            $organizationId,
            [
                'email_type' => 'magic_link'
            ]
        );
    }

    /**
     * Send welcome email (delegates to MemberRegistrationService)
     */
    public function sendWelcomeEmail($member, $organizationId)
    {
        $templatePath = __DIR__ . '/../../templates/portal/welcome.html';
        $memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
        $browseEventsUrl = $this->getBaseUrl() . '/portal/events.php';
        
        if (file_exists($templatePath)) {
            $body = file_get_contents($templatePath);
            $body = str_replace('{first_name}', $member['first_name'] ?? '', $body);
            $body = str_replace('{full_name}', $memberName, $body);
            $body = str_replace('{email}', $member['email'] ?? '', $body);
            $body = str_replace('{browse_events_url}', $browseEventsUrl, $body);
        } else {
            $body = "
                <h2>Welcome, {$member['first_name']}!</h2>
                <p>Thank you for registering with us. Your account has been created successfully.</p>
                <p>You can now browse and RSVP to events, view your event history, and manage your profile.</p>
                <p><a href=\"{$browseEventsUrl}\">Browse Events</a></p>
            ";
        }

        $subject = "Welcome! Your Account Has Been Created";

        return $this->sendEmail(
            $member['email'],
            $subject,
            $body,
            $organizationId,
            [
                'email_type' => 'welcome',
                'user_id' => $member['id']
            ]
        );
    }

    /**
     * Get base URL (config-aware for cron/CLI)
     */
    private function getBaseUrl()
    {
        $configFile = defined('CONFIG_PATH') ? CONFIG_PATH . '/config.php' : __DIR__ . '/../../config/config.php';
        if (file_exists($configFile)) {
            $config = require $configFile;
            return headcount_portal_base_url($config);
        }

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $basePath = dirname($scriptName);
        $basePath = str_replace('/public', '', $basePath);
        $basePath = rtrim($basePath, '/');
        
        return $protocol . '://' . $host . $basePath;
    }
}
