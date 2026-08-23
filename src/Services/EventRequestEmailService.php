<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

/**
 * Transactional email for the event-request workflow.
 */
class EventRequestEmailService extends PortalEmailService
{
    /** @var array<string, mixed> */
    private $config;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        parent::__construct($this->resolveOrgSmtp($config));
    }

    public static function fromConfigFile(): ?self
    {
        $file = dirname(__DIR__, 2) . '/config/config.php';
        if (!is_file($file)) {
            return null;
        }
        $config = require $file;
        return is_array($config) ? new self($config) : null;
    }

    private function resolveOrgSmtp(array $config)
    {
        if (!empty($config['smtp2go'])) {
            return $config['smtp2go'];
        }
        return [];
    }

    public function forOrganization($organizationId)
    {
        $db = Database::getInstance();
        $org = $db->queryOne(
            "SELECT smtp_api_key, smtp_api_key_encrypted, smtp_from_email, smtp_from_name, smtp_reply_to FROM organizations WHERE id = ?",
            [(int) $organizationId]
        );
        if (!$org || empty($org['smtp_from_email'])) {
            return !empty($this->config['smtp2go']) ? $this : null;
        }
        $apiKey = null;
        if (!empty($org['smtp_api_key'])) {
            $apiKey = base64_decode($org['smtp_api_key'], true);
        }
        if (($apiKey === false || $apiKey === '') && !empty($org['smtp_api_key_encrypted']) && !empty($this->config['security']['encryption_key'])) {
            $apiKey = Security::decrypt($org['smtp_api_key_encrypted'], $this->config['security']['encryption_key']);
        }
        if (empty($apiKey) && !empty($this->config['smtp2go'])) {
            return $this;
        }
        if (empty($apiKey)) {
            return null;
        }
        return new self(array_merge($this->config, [
            'smtp2go' => [
                'api_key' => $apiKey,
                'from_email' => $org['smtp_from_email'],
                'from_name' => $org['smtp_from_name'] ?? null,
                'reply_to' => $org['smtp_reply_to'] ?? $org['smtp_from_email'],
            ],
        ]));
    }

    /**
     * @param array<string, mixed> $request
     * @param list<array{id:int,email:string,first_name:string,last_name?:string}> $approvers
     */
    public function notifyApprovers(array $request, array $approvers, bool $resubmitted = false): void
    {
        $orgId = (int) $request['organization_id'];
        $svc = $this->forOrganization($orgId);
        if (!$svc || $approvers === []) {
            return;
        }
        $url = $this->adminRequestUrl($orgId, (int) $request['id']);
        $verb = $resubmitted ? 'resubmitted' : 'submitted';
        $subject = ($resubmitted ? 'Event request resubmitted: ' : 'New event request: ') . ($request['title'] ?? '');
        $body = '
            <h2>' . ($resubmitted ? 'Event request resubmitted' : 'New event request') . '</h2>
            <p>An event request has been ' . htmlspecialchars($verb) . ' and needs your review.</p>
            ' . $this->formatRequestDetails($request) . '
            <p><strong>Requested by:</strong> ' . htmlspecialchars((string) ($request['submitter_name'] ?? '')) . '</p>
            <p style="margin-top:20px;"><a href="' . htmlspecialchars($url) . '" style="display:inline-block;background:#3B82F6;color:white;padding:10px 20px;text-decoration:none;border-radius:6px;font-weight:bold;">Review request</a></p>';

        foreach ($approvers as $approver) {
            if (empty($approver['email'])) {
                continue;
            }
            $svc->sendEmail(
                $approver['email'],
                $subject,
                $body,
                $orgId,
                ['email_type' => 'event_request_approver_notify', 'user_id' => $approver['id'] ?? null]
            );
        }
    }

    /**
     * @param array<string, mixed> $request
     */
    public function notifySentBack(array $request, string $comment): void
    {
        $this->sendToSubmitter(
            $request,
            'Your event request needs updates',
            '
            <h2>Please update your event request</h2>
            <p>Hello ' . htmlspecialchars($this->submitterFirstName($request)) . ',</p>
            <p>Your event request <strong>' . htmlspecialchars((string) ($request['title'] ?? '')) . '</strong> was sent back. Please make the requested updates and resubmit.</p>
            ' . $this->formatRequestDetails($request) . '
            <p><strong>Reviewer comments:</strong><br>' . nl2br(htmlspecialchars($comment)) . '</p>
            <p style="margin-top:20px;"><a href="' . htmlspecialchars($this->adminFormUrl($request)) . '" style="display:inline-block;background:#3B82F6;color:white;padding:10px 20px;text-decoration:none;border-radius:6px;font-weight:bold;">Update request</a></p>',
            'event_request_sent_back'
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    public function notifyDeclined(array $request, string $reason): void
    {
        $this->sendToSubmitter(
            $request,
            'Your event request was declined',
            '
            <h2>Event request declined</h2>
            <p>Hello ' . htmlspecialchars($this->submitterFirstName($request)) . ',</p>
            <p>Unfortunately your event request <strong>' . htmlspecialchars((string) ($request['title'] ?? '')) . '</strong> has been declined.</p>
            ' . $this->formatRequestDetails($request) . '
            ' . ($reason !== '' ? '<p><strong>Reason:</strong><br>' . nl2br(htmlspecialchars($reason)) . '</p>' : '') . '
            <p>You may submit a new request if you have a different proposal.</p>',
            'event_request_declined'
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    public function notifyApproved(array $request): void
    {
        $eventId = (int) ($request['event_id'] ?? 0);
        $url = $eventId > 0
            ? $this->adminEventEditUrl($eventId)
            : $this->adminRequestUrl((int) $request['organization_id'], (int) $request['id']);
        $this->sendToSubmitter(
            $request,
            'Your event request was approved',
            '
            <h2>Event request approved</h2>
            <p>Hello ' . htmlspecialchars($this->submitterFirstName($request)) . ',</p>
            <p>Your event request <strong>' . htmlspecialchars((string) ($request['title'] ?? '')) . '</strong> has been approved. A draft event was created from your proposal.</p>
            ' . $this->formatRequestDetails($request) . '
            <p>Please complete the remaining details and publish the event when it is ready.</p>
            <p style="margin-top:20px;"><a href="' . htmlspecialchars($url) . '" style="display:inline-block;background:#3B82F6;color:white;padding:10px 20px;text-decoration:none;border-radius:6px;font-weight:bold;">Complete event</a></p>',
            'event_request_approved'
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function sendToSubmitter(array $request, string $subject, string $body, string $emailType): void
    {
        $orgId = (int) $request['organization_id'];
        $svc = $this->forOrganization($orgId);
        $email = (string) ($request['submitter_email'] ?? '');
        if (!$svc || $email === '') {
            return;
        }
        $svc->sendEmail(
            $email,
            $subject,
            $body,
            $orgId,
            ['email_type' => $emailType, 'user_id' => $request['submitted_by'] ?? null]
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    private function formatRequestDetails(array $request): string
    {
        $date = !empty($request['event_date']) ? date('F j, Y', strtotime((string) $request['event_date'])) : '—';
        $time = trim((string) ($request['start_time'] ?? ''));
        if ($time !== '') {
            $time = date('g:i A', strtotime($time));
        }
        $end = trim((string) ($request['end_time'] ?? ''));
        if ($end !== '') {
            $time = ($time !== '' ? $time . ' – ' : '') . date('g:i A', strtotime($end));
        }
        $budget = isset($request['budget']) && $request['budget'] !== null && $request['budget'] !== ''
            ? '$' . number_format((float) $request['budget'], 2)
            : '—';

        return '<table style="border-collapse:collapse;margin:16px 0;width:100%;max-width:520px;">
            <tr><td style="padding:6px 0;color:#64748b;">Title</td><td style="padding:6px 0;">' . htmlspecialchars((string) ($request['title'] ?? '')) . '</td></tr>
            <tr><td style="padding:6px 0;color:#64748b;">Date</td><td style="padding:6px 0;">' . htmlspecialchars($date) . '</td></tr>
            <tr><td style="padding:6px 0;color:#64748b;">Time</td><td style="padding:6px 0;">' . htmlspecialchars($time !== '' ? $time : '—') . '</td></tr>
            <tr><td style="padding:6px 0;color:#64748b;">Location</td><td style="padding:6px 0;">' . htmlspecialchars((string) ($request['location'] ?? 'TBD')) . '</td></tr>
            <tr><td style="padding:6px 0;color:#64748b;">Budget</td><td style="padding:6px 0;">' . htmlspecialchars($budget) . '</td></tr>
            <tr><td style="padding:6px 0;color:#64748b;">Audience</td><td style="padding:6px 0;">' . htmlspecialchars((string) ($request['target_audience'] ?? '—')) . '</td></tr>
        </table>';
    }

    /**
     * @param array<string, mixed> $request
     */
    private function submitterFirstName(array $request): string
    {
        $name = trim((string) ($request['submitter_name'] ?? ''));
        if ($name === '') {
            return 'there';
        }
        return explode(' ', $name)[0];
    }

    private function adminRequestUrl(int $organizationId, int $requestId): string
    {
        return headcount_app_base_url($this->loadAppConfig()) . '/admin/index.php?page=event-request-details&id=' . $requestId;
    }

    /**
     * @param array<string, mixed> $request
     */
    private function adminFormUrl(array $request): string
    {
        return headcount_app_base_url($this->loadAppConfig()) . '/admin/index.php?page=event-request-form&id=' . (int) $request['id'];
    }

    private function adminEventEditUrl(int $eventId): string
    {
        return headcount_app_base_url($this->loadAppConfig()) . '/admin/index.php?page=event-edit&id=' . $eventId;
    }

    private function loadAppConfig(): array
    {
        if (!empty($this->config['app']) || !empty($this->config['smtp2go'])) {
            return $this->config;
        }
        $configFile = dirname(__DIR__, 2) . '/config/config.php';
        if (is_file($configFile)) {
            $loaded = require $configFile;
            return is_array($loaded) ? $loaded : [];
        }
        return is_array($this->config) ? $this->config : [];
    }
}
