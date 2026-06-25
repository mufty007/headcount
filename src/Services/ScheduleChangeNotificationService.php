<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Sends automatic emails when event or program date, time, or location changes.
 */
class ScheduleChangeNotificationService
{
    private array $config;
    private EmailService $email;

    public function __construct(array $config)
    {
        $this->config = $config;
        $smtp = $config['smtp2go'] ?? [];
        $this->email = new EmailService($smtp);
    }

    /**
     * @param array<string,mixed> $before Row before update (event_date, start_time, end_time, location, title)
     */
    public function notifyEventIfScheduleChanged(int $eventId, int $organizationId, array $before, array $after): void
    {
        if (trim((string) ($this->config['smtp2go']['api_key'] ?? '')) === '') {
            return;
        }

        $changes = $this->detectChanges([
            'event_date' => 'Date',
            'start_time' => 'Start time',
            'end_time' => 'End time',
            'location' => 'Location',
        ], $before, $after);

        if ($changes === []) {
            return;
        }

        $db = Database::getInstance();
        $rsvpSourceId = EventSeriesHelper::getRsvpSourceEventId($db, $eventId);
        $users = $db->query(
            "SELECT u.id, u.first_name, u.last_name, u.email
             FROM rsvps r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.event_id = :eid AND LOWER(r.status) = 'yes'
               AND u.email IS NOT NULL AND u.email != ''",
            ['eid' => $rsvpSourceId]
        );
        $users = array_values(array_filter($users, static fn ($u) => filter_var($u['email'], FILTER_VALIDATE_EMAIL)));
        if ($users === []) {
            return;
        }

        $title = (string) ($after['title'] ?? $before['title'] ?? 'Event');
        $tpl = $this->loadTemplate($db, $organizationId);
        $summary = $this->formatChangeSummary($changes);
        $org = $db->queryOne('SELECT name, logo_path FROM organizations WHERE id = :id', ['id' => $organizationId]);
        $appUrl = rtrim($this->config['app']['url'] ?? '', '/');
        $logoUrl = !empty($org['logo_path']) ? buildLogoUrlForEmail($appUrl, $org['logo_path']) : null;
        $branding = [
            'org_name' => $org['name'] ?? '',
            'logo_url' => $logoUrl,
            'event_id' => $eventId,
            'template' => 'schedule_change',
        ];

        $eventDate = $this->formatDate($after['event_date'] ?? $before['event_date'] ?? '');
        $eventTime = $this->formatTimeRange($after['start_time'] ?? $before['start_time'] ?? null, $after['end_time'] ?? $before['end_time'] ?? null);
        $location = (string) ($after['location'] ?? $before['location'] ?? '');
        $portalUrl = headcount_event_portal_url($this->config, $eventId);

        $recipients = [];
        foreach ($users as $user) {
            $recipients[] = [
                'id' => (int) $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'] ?? '',
                'last_name' => $user['last_name'] ?? '',
                'event_name' => $title,
                'event_date' => $eventDate,
                'event_time' => $eventTime,
                'event_location' => $location,
                'location' => $location,
                'change_summary' => $summary,
                'event_link' => $portalUrl,
                'organization_name' => $org['name'] ?? '',
            ];
        }

        $subject = str_replace('{event_name}', $title, $tpl['subject']);
        $subject = str_replace('{program_name}', $title, $subject);
        try {
            $this->email->sendBulk($recipients, $subject, $tpl['body_html'], $organizationId, $branding);
        } catch (\Throwable $e) {
            error_log('ScheduleChangeNotificationService event: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $before Program row before update
     * @param array<string,mixed> $after  Program row after update (merged)
     */
    public function notifyProgramIfScheduleChanged(int $programId, int $organizationId, array $before, array $after): void
    {
        if (trim((string) ($this->config['smtp2go']['api_key'] ?? '')) === '') {
            return;
        }

        $fields = [
            'location' => 'Location',
            'session_start_time' => 'Start time',
            'session_end_time' => 'End time',
            'starts_on' => 'Program start date',
            'ends_on' => 'Program end date',
        ];
        $changes = $this->detectChanges($fields, $before, $after);
        if ($changes === []) {
            return;
        }

        $db = Database::getInstance();
        $users = $db->query(
            "SELECT u.id, u.first_name, u.last_name, u.email
             FROM program_registrations r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.program_id = :pid AND r.status = 'active'
               AND u.email IS NOT NULL AND u.email != ''",
            ['pid' => $programId]
        );
        $users = array_values(array_filter($users, static fn ($u) => filter_var($u['email'], FILTER_VALIDATE_EMAIL)));
        if ($users === []) {
            return;
        }

        $title = (string) ($after['title'] ?? $before['title'] ?? 'Program');
        $tpl = $this->loadTemplate($db, $organizationId);
        $summary = $this->formatChangeSummary($changes);
        $org = $db->queryOne('SELECT name, logo_path FROM organizations WHERE id = :id', ['id' => $organizationId]);
        $appUrl = rtrim($this->config['app']['url'] ?? '', '/');
        $logoUrl = !empty($org['logo_path']) ? buildLogoUrlForEmail($appUrl, $org['logo_path']) : null;
        $branding = [
            'org_name' => $org['name'] ?? '',
            'logo_url' => $logoUrl,
            'program_id' => $programId,
            'template' => 'schedule_change',
        ];

        $location = (string) ($after['location'] ?? $before['location'] ?? '');
        $time = $this->formatTimeRange($after['session_start_time'] ?? $before['session_start_time'] ?? null, $after['session_end_time'] ?? $before['session_end_time'] ?? null);
        $startsOn = $this->formatDate($after['starts_on'] ?? $before['starts_on'] ?? '');
        $portalUrl = headcount_program_portal_url($this->config, $programId);

        $recipients = [];
        foreach ($users as $user) {
            $recipients[] = [
                'id' => (int) $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'] ?? '',
                'last_name' => $user['last_name'] ?? '',
                'program_name' => $title,
                'event_name' => $title,
                'event_location' => $location,
                'location' => $location,
                'event_time' => $time,
                'event_date' => $startsOn,
                'next_session_date' => $startsOn . ($time !== '' ? ' · ' . $time : ''),
                'change_summary' => $summary,
                'event_link' => $portalUrl,
                'organization_name' => $org['name'] ?? '',
            ];
        }

        $subject = str_replace('{program_name}', $title, $tpl['subject']);
        $subject = str_replace('{event_name}', $title, $subject);
        try {
            $this->email->sendBulk($recipients, $subject, $tpl['body_html'], $organizationId, $branding);
        } catch (\Throwable $e) {
            error_log('ScheduleChangeNotificationService program: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,string> $fieldLabels
     * @return list<array{label:string,from:string,to:string}>
     */
    private function detectChanges(array $fieldLabels, array $before, array $after): array
    {
        $changes = [];
        foreach ($fieldLabels as $field => $label) {
            $old = $this->normalizeFieldValue($field, $before[$field] ?? null);
            $new = $this->normalizeFieldValue($field, $after[$field] ?? null);
            if ($old !== $new) {
                $changes[] = [
                    'label' => $label,
                    'from' => $this->displayValue($field, $before[$field] ?? null),
                    'to' => $this->displayValue($field, $after[$field] ?? null),
                ];
            }
        }
        return $changes;
    }

    private function normalizeFieldValue(string $field, $value): string
    {
        if ($value === null) {
            return '';
        }
        $s = trim((string) $value);
        if ($s === '') {
            return '';
        }
        if (str_contains($field, 'time')) {
            if (preg_match('/^(\d{1,2}):(\d{2})/', $s, $m)) {
                return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
            }
        }
        if (str_contains($field, 'date') || $field === 'starts_on' || $field === 'ends_on') {
            if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) {
                return $m[1];
            }
        }
        return mb_strtolower($s);
    }

    private function displayValue(string $field, $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '(not set)';
        }
        if (str_contains($field, 'time') && !str_contains($field, 'date')) {
            return $this->formatTime((string) $value);
        }
        if (str_contains($field, 'date') || $field === 'starts_on' || $field === 'ends_on') {
            return $this->formatDate((string) $value);
        }
        return trim((string) $value);
    }

    /**
     * @param list<array{label:string,from:string,to:string}> $changes
     */
    private function formatChangeSummary(array $changes): string
    {
        $lines = [];
        foreach ($changes as $c) {
            $lines[] = '<li><strong>' . htmlspecialchars($c['label'], ENT_QUOTES, 'UTF-8') . ':</strong> '
                . htmlspecialchars($c['from'], ENT_QUOTES, 'UTF-8') . ' → '
                . htmlspecialchars($c['to'], ENT_QUOTES, 'UTF-8') . '</li>';
        }
        return '<ul style="margin:0;padding-left:1.25rem;">' . implode('', $lines) . '</ul>';
    }

    private function formatDate(string $date): string
    {
        $date = trim($date);
        if ($date === '') {
            return '';
        }
        $ts = strtotime($date);
        return $ts !== false ? date('F j, Y', $ts) : $date;
    }

    private function formatTime(string $time): string
    {
        $time = trim($time);
        if ($time === '') {
            return '';
        }
        $ts = strtotime($time);
        return $ts !== false ? date('g:i A', $ts) : $time;
    }

    private function formatTimeRange($start, $end): string
    {
        $s = $this->formatTime((string) ($start ?? ''));
        $e = $this->formatTime((string) ($end ?? ''));
        if ($s === '' && $e === '') {
            return '';
        }
        if ($s !== '' && $e !== '') {
            return $s . ' – ' . $e;
        }
        return $s !== '' ? $s : $e;
    }

    /**
     * @return array{subject:string,body_html:string}
     */
    private function loadTemplate(Database $db, int $organizationId): array
    {
        $defaults = [
            'subject' => 'Updated: {event_name}',
            'body_html' => '<h2>Schedule update</h2>'
                . '<p>Hi {first_name},</p>'
                . '<p>There has been a change to <strong>{event_name}</strong>:</p>'
                . '{change_summary}'
                . '<p><strong>Current details</strong><br>'
                . 'Date: {event_date}<br>'
                . 'Time: {event_time}<br>'
                . 'Location: {event_location}</p>'
                . '<p><a href="{event_link}">View details</a></p>',
        ];

        try {
            $tpl = $db->queryOne(
                "SELECT subject, body_html FROM email_templates
                 WHERE organization_id = ? AND template_type = 'schedule_change' LIMIT 1",
                [$organizationId]
            );
            if ($tpl && trim((string) ($tpl['subject'] ?? '')) !== '' && trim((string) ($tpl['body_html'] ?? '')) !== '') {
                return [
                    'subject' => (string) $tpl['subject'],
                    'body_html' => (string) $tpl['body_html'],
                ];
            }
            $tpl = $db->queryOne(
                "SELECT subject, body_html FROM email_templates
                 WHERE is_default = 1 AND template_type = 'schedule_change' LIMIT 1"
            );
            if ($tpl && trim((string) ($tpl['subject'] ?? '')) !== '' && trim((string) ($tpl['body_html'] ?? '')) !== '') {
                return [
                    'subject' => (string) $tpl['subject'],
                    'body_html' => (string) $tpl['body_html'],
                ];
            }
        } catch (\Throwable $e) {
            error_log('ScheduleChangeNotificationService template load: ' . $e->getMessage());
        }

        return $defaults;
    }
}
