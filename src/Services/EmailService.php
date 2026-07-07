<?php

namespace Headcount\Services;

use Exception;
use Headcount\Models\EmailLog;
use Headcount\Models\User;
use Headcount\Models\Event;
use Headcount\Services\ActivityLogger;

/**
 * Email Service
 * Handles email sending via SMTP2GO
 */
class EmailService
{
    private $emailLogModel;
    private $apiKey;
    private $fromEmail;
    private $fromName;
    private $replyTo;
    private $apiUrl = 'https://api.smtp2go.com/v3/email/send';

    public function __construct($config)
    {
        $this->emailLogModel = new EmailLog();
        $this->apiKey = $config['api_key'] ?? '';
        $this->fromEmail = $config['from_email'] ?? '';
        $this->fromName = $config['from_name'] ?? '';
        $this->replyTo = $config['reply_to'] ?? $this->fromEmail;
    }

    /**
     * Send single email
     */
    public function sendEmail($to, $subject, $body, $organizationId, $options = [])
    {
        if (trim((string) $this->apiKey) === '' || trim((string) $this->fromEmail) === '') {
            return ['success' => false, 'error' => 'Email service is not configured (SMTP API key and from address are required).'];
        }

        if (isset($options['logo_url']) || isset($options['org_name'])) {
            $body = \wrapEmailWithBranding($body, $options['logo_url'] ?? null, $options['org_name'] ?? '');
        }
        $data = [
            'api_key' => $this->apiKey,
            'to' => [$to],
            'sender' => $this->fromEmail,
            'subject' => $subject,
            'html_body' => $body,
        ];

        if (!empty($this->fromName)) {
            $data['sender'] = "{$this->fromName} <{$this->fromEmail}>";
        }

        if (!empty($this->replyTo)) {
            $data['reply_to'] = $this->replyTo;
        }

        // Create email log entry (email_logs.organization_id is NOT NULL; skip if missing)
        $logId = null;
        $logData = [
            'organization_id' => $organizationId,
            'event_id' => $options['event_id'] ?? null,
            'user_id' => $options['user_id'] ?? null,
            'to_email' => $to,
            'subject' => $subject,
            'template' => $options['template'] ?? 'custom',
            'status' => 'queued',
        ];
        if (isset($options['campaign_id'])) {
            $logData['campaign_id'] = $options['campaign_id'];
        }
        if (isset($options['program_id'])) {
            $logData['program_id'] = $options['program_id'];
        }
        if ($organizationId !== null && $organizationId !== '' && (int) $organizationId > 0) {
            $logData['organization_id'] = (int) $organizationId;
            $logId = $this->emailLogModel->create($logData)['id'];
        }

        // Send email via SMTP2GO API (with optional retry on rate limit)
        $response = $this->sendViaSmtp2Go($data);
        $httpCode = $response['http_code'];
        $responseBody = $response['body'];
        $responseData = json_decode($responseBody, true);
        if (!is_array($responseData)) {
            $responseData = [];
        }

        // Retry once after delay if rate limited (429 or provider probation/limit message)
        if ($httpCode === 429 || ($httpCode === 200 && isset($responseData['data']['error_code']) && stripos($responseData['data']['error'] ?? '', 'rate') !== false)) {
            usleep(2000000); // 2 seconds
            $response = $this->sendViaSmtp2Go($data);
            $httpCode = $response['http_code'];
            $responseBody = $response['body'];
            $responseData = json_decode($responseBody, true);
            if (!is_array($responseData)) {
                $responseData = [];
            }
        }

        // Update email log
        if ($httpCode === 200 && isset($responseData['data']['email_id'])) {
            $emailId = $responseData['data']['email_id'];
            $requestId = $responseData['request_id'] ?? '';
            if ($requestId !== '') {
                error_log('SMTP2GO accepted email_id=' . $emailId . ' request_id=' . $requestId);
            } else {
                error_log('SMTP2GO accepted email_id=' . $emailId);
            }
            if ($logId !== null) {
                $this->emailLogModel->updateStatus($logId, 'sent', $responseBody);
                $this->emailLogModel->updateSmtpMessageId($logId, $emailId);
            }
            // Log activity
            $activityLogger = new ActivityLogger($organizationId, $options['user_id'] ?? null);
            $activityLogger->logEmailSent(
                $to,
                $subject,
                $options['template'] ?? $options['email_type'] ?? null,
                $options['event_id'] ?? null,
                $options['user_id'] ?? null,
                'sent'
            );
            
            return ['success' => true, 'email_id' => $emailId, 'request_id' => $requestId];
        } else {
            if ($logId !== null) {
                $this->emailLogModel->updateStatus($logId, 'failed', $responseBody);
            }
            
            // Log failed email activity
            $activityLogger = new ActivityLogger($organizationId, $options['user_id'] ?? null);
            $activityLogger->logEmailSent(
                $to,
                $subject,
                $options['template'] ?? $options['email_type'] ?? null,
                $options['event_id'] ?? null,
                $options['user_id'] ?? null,
                'failed'
            );
            
            $errMsg = $responseData['data']['error'] ?? $responseData['data']['error_code'] ?? $responseData['error'] ?? '';
            if ($errMsg === '' && $responseBody !== '') {
                $errMsg = substr($responseBody, 0, 400);
            }
            if ($errMsg === '') {
                $errMsg = 'Unknown error (HTTP ' . $httpCode . ')';
            }
            if ($httpCode === 429) {
                $errMsg = 'Rate limit exceeded. Try sending fewer emails at once or later. ' . $errMsg;
            }
            return ['success' => false, 'error' => $errMsg];
        }
    }

    /**
     * Perform one SMTP2GO API request; returns ['http_code' => int, 'body' => string].
     */
    private function sendViaSmtp2Go(array $data)
    {
        $apiKey = (string) ($data['api_key'] ?? '');
        unset($data['api_key']);
        $data['fastaccept'] = true;

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Smtp2go-Api-Key: ' . $apiKey,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $curlErr = curl_error($ch);
            curl_close($ch);
            return [
                'http_code' => 0,
                'body' => json_encode(['data' => ['error' => 'Network error contacting email provider: ' . $curlErr]]),
            ];
        }
        curl_close($ch);
        return ['http_code' => $httpCode, 'body' => $body];
    }

    /**
     * Resend an email and update an existing log entry (used for "Resend" from Email Log).
     * Does not create a new log entry; updates the given log id to sent/failed.
     */
    public function resendToLog($logId, $to, $subject, $body, $organizationId)
    {
        $data = [
            'api_key' => $this->apiKey,
            'to' => [$to],
            'sender' => $this->fromEmail,
            'subject' => $subject,
            'html_body' => $body,
        ];

        if (!empty($this->fromName)) {
            $data['sender'] = "{$this->fromName} <{$this->fromEmail}>";
        }

        if (!empty($this->replyTo)) {
            $data['reply_to'] = $this->replyTo;
        }

        $response = $this->sendViaSmtp2Go($data);
        $httpCode = $response['http_code'];
        $responseBody = $response['body'];
        $responseData = json_decode($responseBody, true);
        if (!is_array($responseData)) {
            $responseData = [];
        }

        if ($httpCode === 200 && isset($responseData['data']['email_id'])) {
            $emailId = $responseData['data']['email_id'];
            $requestId = $responseData['request_id'] ?? '';
            if ($requestId !== '') {
                error_log('SMTP2GO resend email_id=' . $emailId . ' request_id=' . $requestId);
            }
            $this->emailLogModel->updateStatus($logId, 'sent', $responseBody);
            return ['success' => true, 'email_id' => $emailId, 'request_id' => $requestId];
        }

        $this->emailLogModel->updateStatus($logId, 'failed', $responseBody);
        $errMsg = $responseData['data']['error'] ?? $responseData['data']['error_code'] ?? $responseData['error'] ?? $responseBody;
        return ['success' => false, 'error' => is_string($errMsg) ? $errMsg : json_encode($errMsg)];
    }

    /**
     * Send bulk emails with rate limiting.
     * Defaults are conservative to avoid SMTP2GO probation/rate limits (e.g. 1000/day on new accounts).
     */
    public function sendBulk($recipients, $subject, $body, $organizationId, $options = [])
    {
        $batchSize = $options['batch_size'] ?? 50;
        $rateLimit = $options['rate_limit'] ?? 30; // emails per minute (conservative for provider limits)
        $delay = 60 / max(1, $rateLimit);

        $results = [
            'sent' => 0,
            'failed' => 0,
            'errors' => []
        ];

        $batches = array_chunk($recipients, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $recipientIndex => $recipient) {
                try {
                    $mergedSubject = $this->processTemplate($subject, $recipient);
                    $result = $this->sendEmail(
                        $recipient['email'],
                        $mergedSubject,
                        $this->processTemplate($body, $recipient),
                        $organizationId,
                        array_merge($options, ['user_id' => $recipient['id'] ?? null])
                    );

                    if ($result['success']) {
                        $results['sent']++;
                    } else {
                        $results['failed']++;
                        $results['errors'][] = [
                            'email' => $recipient['email'],
                            'error' => $result['error'] ?? 'Unknown error'
                        ];
                    }
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['errors'][] = [
                        'email' => $recipient['email'],
                        'error' => $e->getMessage()
                    ];
                }

                // Reduced rate limiting - only delay if not the last item in batch
                if ($recipientIndex < count($batch) - 1) {
                    usleep($delay * 1000000); // Convert to microseconds
                }
            }

            // Reduced wait time between batches - only if not the last batch
            if ($batchIndex < count($batches) - 1) {
                usleep(500000); // 0.5 seconds instead of 1 second
            }
        }

        return $results;
    }

    /**
     * Process email template with merge tags
     */
    public function processTemplate($template, $data)
    {
        $firstName = $this->plainTextMergeValue($data['first_name'] ?? '');
        $lastName = $this->plainTextMergeValue($data['last_name'] ?? '');
        $fullName = trim($firstName . ' ' . $lastName);
        $eventDateRaw = trim((string) ($data['event_date'] ?? ''));
        $eventDayName = '';
        if ($eventDateRaw !== '') {
            $ts = strtotime($eventDateRaw);
            if ($ts !== false) {
                $eventDayName = date('l', $ts);
            }
        }

        $replacements = [
            '{first_name}' => $firstName,
            '{last_name}' => $lastName,
            '{full_name}' => $fullName,
            '{name}' => $fullName !== '' ? $fullName : ($firstName !== '' ? $firstName : ''),
            '{email}' => $this->plainTextMergeValue($data['email'] ?? ''),
            '{phone}' => $this->plainTextMergeValue($data['phone'] ?? ''),
            '{event_name}' => $this->plainTextMergeValue($data['event_name'] ?? ''),
            '{event_date}' => $eventDateRaw,
            '{event_day}' => $eventDayName,
            '{event_day_name}' => $eventDayName,
            '{event_time}' => $this->plainTextMergeValue($data['event_time'] ?? ''),
            '{event_location}' => $this->plainTextMergeValue($data['event_location'] ?? ''),
            '{location}' => $this->plainTextMergeValue($data['event_location'] ?? $data['location'] ?? ''),
            '{join_link}' => $data['join_link'] ?? '',
            '{feedback_link}' => $data['feedback_link'] ?? '',
            '{event_link}' => $data['event_link'] ?? $data['feedback_link'] ?? '',
            '{event_description}' => $data['event_description'] ?? '',
            '{organization_name}' => $this->plainTextMergeValue($data['organization_name'] ?? ''),
            '{program_name}' => $this->plainTextMergeValue($data['program_name'] ?? ''),
            '{program_description}' => $data['program_description'] ?? '',
            '{next_session_date}' => $this->plainTextMergeValue($data['next_session_date'] ?? ''),
            '{change_summary}' => $data['change_summary'] ?? '',
        ];

        $processed = $template;
        foreach ($replacements as $tag => $value) {
            $processed = str_replace($tag, (string) $value, $processed);
        }

        return $processed;
    }

    /**
     * Decode over-escaped ampersands in plain-text merge values (legacy DB rows, subject lines).
     */
    private function plainTextMergeValue($value): string
    {
        $text = trim((string) $value);
        if ($text === '') {
            return '';
        }
        if (\function_exists('headcount_flatten_ampersand_in_plain_text')) {
            return headcount_flatten_ampersand_in_plain_text($text);
        }
        return trim(html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Send event announcement
     */
    public function sendEventAnnouncement($eventId, $organizationId, $recipientIds = null, $options = [], $branding = null)
    {
        $eventModel = new Event();
        $userModel = new User();

        $event = $eventModel->find($eventId);
        if (!$event) {
            throw new \Exception('Event not found', 404);
        }

        // Get recipients
        if ($recipientIds === null) {
            $recipients = $userModel->getAll($organizationId, ['status' => 'active'], 10000, 0);
        } else {
            $recipients = [];
            foreach ($recipientIds as $userId) {
                $user = $userModel->find($userId);
                if ($user && $user['status'] === 'active' && !empty($user['email'])) {
                    $recipients[] = $user;
                }
            }
        }

        // Filter recipients with email addresses
        $recipients = array_filter($recipients, function($user) {
            return !empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL);
        });

        if (empty($recipients)) {
            throw new \Exception('No valid recipients found', 400);
        }

        // Prepare email content
        $subjectTemplate = $options['subject'] ?? "Event Announcement: {event_name}";
        $template = $options['body'] ?? $this->getAnnouncementTemplate();
        $templateType = $options['template_type'] ?? 'announcement';

        $isVirtual = !empty($event['is_virtual']);
        $joinLink = ($isVirtual && !empty($event['location'])) ? $event['location'] : '';

        // Format recipients for bulk send
        $recipientData = [];
        foreach ($recipients as $user) {
            $recipientData[] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'event_name' => $event['title'],
                'event_date' => date('F j, Y', strtotime($event['event_date'])),
                'event_time' => date('g:i A', strtotime($event['start_time'])),
                'event_location' => $event['location'] ?? '',
                'join_link' => $joinLink,
                'event_description' => $event['description'] ?? '',
            ];
        }

        return $this->sendBulk($recipientData, $subjectTemplate, $template, $organizationId, array_merge([
            'event_id' => $eventId,
            'template' => $templateType
        ], $branding ?? []));
    }

    /**
     * Send event reminder to registered attendees (RSVP yes) for a specific event.
     * $options may include: subject (template string), body (HTML template string), template_type (e.g. reminder_1day)
     */
    public function sendEventReminder($eventId, $organizationId, $recipientIds, $options = [], $branding = null)
    {
        $eventModel = new Event();
        $userModel = new User();

        $event = $eventModel->find($eventId);
        if (!$event) {
            throw new \Exception('Event not found', 404);
        }

        $recipients = [];
        foreach ($recipientIds as $userId) {
            $user = $userModel->find($userId);
            if ($user && !empty($user['email']) && filter_var($user['email'], FILTER_VALIDATE_EMAIL)) {
                $recipients[] = $user;
            }
        }

        if (empty($recipients)) {
            throw new \Exception('No valid recipients found for reminder', 400);
        }

        $subjectTemplate = $options['subject'] ?? 'Reminder: {event_name} on {event_day}, {event_date}';
        $bodyTemplate = $options['body'] ?? $this->getDefaultReminderBody();
        $templateType = $options['template_type'] ?? 'reminder_1day';

        $isVirtual = !empty($event['is_virtual']);
        $joinLink = ($isVirtual && !empty($event['location'])) ? $event['location'] : '';

        $recipientData = [];
        foreach ($recipients as $user) {
            $recipientData[] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'event_name' => $event['title'],
                'event_date' => date('F j, Y', strtotime($event['event_date'])),
                'event_time' => date('g:i A', strtotime($event['start_time'] ?? '00:00')),
                'event_location' => $event['location'] ?? '',
                'join_link' => $joinLink,
                'event_description' => $event['description'] ?? '',
            ];
        }

        $subject = $this->processTemplate($subjectTemplate, $recipientData[0] ?? []);
        return $this->sendBulk($recipientData, $subject, $bodyTemplate, $organizationId, array_merge([
            'event_id' => $eventId,
            'template' => $templateType
        ], $branding ?? []));
    }

    /**
     * Default reminder email body (used when no org template is set)
     */
    private function getDefaultReminderBody()
    {
        return '<h2>Reminder</h2><p>Hi {first_name},</p><p>This is a friendly reminder that <strong>{event_name}</strong> is coming up.</p><p><strong>Date:</strong> {event_date}<br><strong>Time:</strong> {event_time}<br><strong>Location:</strong> {event_location}</p><p>We look forward to seeing you there!</p>';
    }

    /**
     * Get announcement email template
     */
    private function getAnnouncementTemplate()
    {
        return file_get_contents(__DIR__ . '/../../templates/announcement.html') ?: '
            <h2>Event Announcement: {event_name}</h2>
            <p>Hello {first_name},</p>
            <p>We are excited to announce an upcoming event:</p>
            <p><strong>{event_name}</strong><br>
            Date: {event_date}<br>
            Time: {event_time}<br>
            Location: {event_location}</p>
            <p>{event_description}</p>
            <p>We hope to see you there!</p>
        ';
    }

    /**
     * Send announcement to all active registrants of a program.
     *
     * @param int $programId
     * @param int $organizationId
     * @param string $subject
     * @param string $htmlBody Use {first_name}, {program_name}, {program_description}, {next_session_date}
     * @param array $branding Optional logo_url, org_name for wrapper
     */
    public function sendProgramAnnouncement($programId, $organizationId, $subject, $htmlBody, $branding = [])
    {
        $db = \Headcount\Helpers\Database::getInstance();
        $program = $db->queryOne(
            "SELECT * FROM programs WHERE id = :id AND organization_id = :org",
            ['id' => $programId, 'org' => $organizationId]
        );
        if (!$program) {
            throw new \Exception('Program not found', 404);
        }
        $users = $db->query(
            "SELECT u.id, u.first_name, u.last_name, u.email
             FROM program_registrations r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.program_id = :pid AND r.status = 'active' AND u.email IS NOT NULL AND u.email != ''",
            ['pid' => $programId]
        );
        $users = array_filter($users, function ($u) {
            return filter_var($u['email'], FILTER_VALIDATE_EMAIL);
        });
        if (empty($users)) {
            throw new \Exception('No active registrants with valid email', 400);
        }
        $next = $db->queryOne(
            "SELECT session_date, start_time FROM program_sessions
             WHERE program_id = :pid AND session_date >= CURDATE() AND status = 'scheduled'
             ORDER BY session_date ASC LIMIT 1",
            ['pid' => $programId]
        );
        $nextDate = $next ? date('F j, Y', strtotime($next['session_date'])) : 'TBD';
        $nextTime = ($next && !empty($next['start_time'])) ? date('g:i A', strtotime($next['start_time'])) : '';

        $recipientData = [];
        foreach ($users as $user) {
            $recipientData[] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'program_name' => $program['title'],
                'program_description' => $program['description'] ?? '',
                'next_session_date' => $nextDate . ($nextTime ? ' ' . $nextTime : ''),
            ];
        }

        $template = $htmlBody;
        return $this->sendBulk($recipientData, $subject, $template, $organizationId, array_merge([
            'program_id' => $programId,
            'template' => 'announcement',
        ], $branding));
    }

    /**
     * Reminder for a specific program session (cron: sessions tomorrow).
     */
    public function sendProgramSessionReminderEmail($programSessionId, $organizationId, $branding = [])
    {
        $db = \Headcount\Helpers\Database::getInstance();
        $sess = $db->queryOne(
            "SELECT s.*, p.id AS program_id, p.title AS program_title, p.description
             FROM program_sessions s
             INNER JOIN programs p ON p.id = s.program_id
             WHERE s.id = :id AND p.organization_id = :org",
            ['id' => $programSessionId, 'org' => $organizationId]
        );
        if (!$sess) {
            throw new \Exception('Session not found', 404);
        }
        $programSvc = new ProgramService();
        $programId = (int) $sess['program_id'];
        $sessionId = (int) $programSessionId;
        $users = $db->query(
            "SELECT u.id, u.first_name, u.last_name, u.email
             FROM program_registrations r
             INNER JOIN users u ON u.id = r.user_id
             WHERE r.program_id = :pid AND r.status = 'active' AND u.email IS NOT NULL",
            ['pid' => $programId]
        );
        $users = array_filter($users, function ($u) use ($programSvc, $programId, $sessionId) {
            if (!filter_var($u['email'], FILTER_VALIDATE_EMAIL)) {
                return false;
            }
            return $programSvc->userHasSessionAccess($programId, (int) $u['id'], $sessionId);
        });
        if (empty($users)) {
            return ['sent' => 0, 'failed' => 0, 'errors' => []];
        }
        $dateStr = date('F j, Y', strtotime($sess['session_date']));
        $timeStr = !empty($sess['start_time']) ? date('g:i A', strtotime($sess['start_time'])) : '';
        $subject = 'Reminder: ' . $sess['program_title'] . ' on ' . $dateStr;
        $body = '<p>Hi {first_name},</p><p>This is a reminder for <strong>{program_name}</strong>.</p>'
            . '<p><strong>Date:</strong> ' . htmlspecialchars($dateStr)
            . '<br><strong>Time:</strong> ' . htmlspecialchars($timeStr) . '</p>'
            . '<p>{program_description}</p>';
        $recipientData = [];
        foreach ($users as $user) {
            $recipientData[] = [
                'id' => $user['id'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'program_name' => $sess['program_title'],
                'program_description' => $sess['description'] ?? '',
                'next_session_date' => $dateStr . ' ' . $timeStr,
            ];
        }
        return $this->sendBulk($recipientData, $subject, $body, $organizationId, array_merge([
            'program_id' => $sess['program_id'],
            'template' => 'reminder',
        ], $branding));
    }

    /**
     * Remind members who started paid program checkout but have not completed payment.
     *
     * @param array{registration_id:int,program_id:int,user_id:int,organization_id:int,program_title:string,first_name:string,last_name:string,email:string} $row
     */
    public function sendProgramPaymentReminderEmail(array $row, string $programPortalUrl, $organizationId, $branding = [])
    {
        $email = trim((string) ($row['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['sent' => 0, 'failed' => 1, 'errors' => ['Invalid email']];
        }
        $programTitle = (string) ($row['program_title'] ?? 'your program');
        $subject = 'Complete your registration for ' . $programTitle;
        $body = '<p>Hi {first_name},</p>'
            . '<p>You started registering for <strong>{program_name}</strong>, but payment has not been completed yet.</p>'
            . '<p>Please complete your payment to secure your spot in the program:</p>'
            . '<p><a href="' . htmlspecialchars($programPortalUrl) . '">Complete registration &amp; payment</a></p>'
            . '<p>If you have already paid, you can ignore this message.</p>';
        $recipientData = [[
            'id' => (int) ($row['user_id'] ?? 0),
            'email' => $email,
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'program_name' => $programTitle,
            'program_description' => '',
            'next_session_date' => '',
        ]];
        return $this->sendBulk($recipientData, $subject, $body, $organizationId, array_merge([
            'program_id' => (int) ($row['program_id'] ?? 0),
            'template' => 'program_payment_reminder',
            'email_type' => 'program_payment_reminder',
        ], $branding));
    }

    /**
     * Notify a sponsored program enrollee (and prompt account completion when needed).
     */
    public function sendSponsoredProgramEnrollmentEmail(
        array $program,
        array $user,
        int $organizationId,
        string $programPortalUrl,
        string $registerUrl,
        bool $needsProfile
    ): array {
        $email = trim((string) ($user['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid email'];
        }
        $title = (string) ($program['title'] ?? 'Program');
        $firstName = (string) ($user['first_name'] ?? '');
        $subject = 'You are enrolled: ' . $title;
        $body = '<p>Hi ' . htmlspecialchars($firstName !== '' ? $firstName : 'there') . ',</p>'
            . '<p>You have been enrolled in <strong>' . htmlspecialchars($title) . '</strong> as a sponsored participant.</p>'
            . '<p><a href="' . htmlspecialchars($programPortalUrl) . '">View program details</a></p>';
        if ($needsProfile) {
            $body .= '<p>To access the member portal and manage your registration, please complete your account:</p>'
                . '<p><a href="' . htmlspecialchars($registerUrl) . '" style="display:inline-block;margin-top:8px;background:#3B82F6;color:white;padding:10px 20px;text-decoration:none;border-radius:6px;font-weight:bold;">Complete your account</a></p>';
        }
        return $this->sendEmail(
            $email,
            $subject,
            $body,
            $organizationId,
            [
                'email_type' => 'program_sponsored_enrollment',
                'program_id' => (int) ($program['id'] ?? 0),
                'user_id' => (int) ($user['id'] ?? 0),
            ]
        );
    }
}
