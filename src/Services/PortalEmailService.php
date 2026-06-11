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
     * Build payment receipt body.
     */
    public function buildPaymentReceiptBody($event, $member, float $amount)
    {
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
        $templatePath = __DIR__ . '/../../templates/portal/rsvp-confirmation.html';

        $memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
        $eventDate = date('F j, Y', strtotime($event['event_date']));
        $eventTime = !empty($event['start_time']) ? date('g:i A', strtotime($event['start_time'])) : '';

        if (file_exists($templatePath)) {
            $body = file_get_contents($templatePath);
            $body = str_replace('{first_name}', $member['first_name'] ?? '', $body);
            $body = str_replace('{full_name}', $memberName, $body);
            $body = str_replace('{event_name}', $event['title'] ?? '', $body);
            $body = str_replace('{event_date}', $eventDate, $body);
            $body = str_replace('{event_time}', $eventTime, $body);
            $body = str_replace('{event_location}', $event['location'] ?? '', $body);
            $joinLink = (!empty($event['is_virtual']) && !empty($event['location'])) ? ($event['location']) : '';
            $body = str_replace('{join_link}', $joinLink, $body);
            $body = str_replace('{event_description}', $event['description'] ?? '', $body);

            $baseUrl = $this->getBaseUrl();
            $calendarLink = $baseUrl . '/api/portal/calendar/event/' . $event['id'] . '.ics';
            $googleCalendarLink = $baseUrl . '/api/portal/calendar/google/' . $event['id'];
            $body = str_replace('{calendar_link}', $calendarLink, $body);
            $body = str_replace('{google_calendar_link}', $googleCalendarLink, $body);
            return $body;
        }

        return "
            <h2>RSVP Confirmation</h2>
            <p>Hello " . ($member['first_name'] ?? '') . ",</p>
            <p>Your RSVP has been confirmed for:</p>
            <h3>" . ($event['title'] ?? '') . "</h3>
            <p><strong>Date:</strong> {$eventDate}</p>
            <p><strong>Time:</strong> {$eventTime}</p>
            <p><strong>Location:</strong> " . ($event['location'] ?? '') . "</p>
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
        $memberName = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));
        $eventDate = date('F j, Y', strtotime($event['event_date']));
        
        $body = "
            <h2>RSVP Cancelled</h2>
            <p>Hello {$member['first_name']},</p>
            <p>Your RSVP has been cancelled for:</p>
            <h3>{$event['title']}</h3>
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
        
        if (file_exists($templatePath)) {
            $body = file_get_contents($templatePath);
            $body = str_replace('{first_name}', $member['first_name'] ?? '', $body);
            $body = str_replace('{full_name}', $memberName, $body);
            $body = str_replace('{email}', $member['email'] ?? '', $body);
        } else {
            $body = "
                <h2>Welcome, {$member['first_name']}!</h2>
                <p>Thank you for registering with us. Your account has been created successfully.</p>
                <p>You can now browse and RSVP to events, view your event history, and manage your profile.</p>
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
     * Get base URL
     */
    private function getBaseUrl()
    {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
        $basePath = dirname($scriptName);
        $basePath = str_replace('/public', '', $basePath);
        $basePath = rtrim($basePath, '/');
        
        return $protocol . '://' . $host . $basePath;
    }
}
