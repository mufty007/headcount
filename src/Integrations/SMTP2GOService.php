<?php

namespace Headcount\Integrations;

use Headcount\Core\Logger;

/**
 * SMTP2GO Service
 * Handles SMTP2GO API integration
 */
class SMTP2GOService
{
    private $apiKey;
    private $apiUrl = 'https://api.smtp2go.com/v3/email/send';
    private $fromEmail;
    private $fromName;
    private $replyTo;

    public function __construct($apiKey, $fromEmail = null, $fromName = null, $replyTo = null)
    {
        $this->apiKey = $apiKey;
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
        $this->replyTo = $replyTo;
    }

    /**
     * Send email via SMTP2GO
     */
    public function sendEmail($to, $subject, $body, $fromEmail = null, $fromName = null)
    {
        $data = [
            'to' => [$to],
            'sender' => $fromEmail ?: $this->fromEmail,
            'subject' => $subject,
            'html_body' => $body,
            'fastaccept' => true,
        ];

        if ($this->fromName) {
            $data['sender'] = "{$this->fromName} <{$data['sender']}>";
        }

        if ($this->replyTo) {
            $data['reply_to'] = $this->replyTo;
        }

        $ch = curl_init($this->apiUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Smtp2go-Api-Key: ' . $this->apiKey,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \Exception("SMTP2GO cURL error: $error");
        }

        $result = json_decode($response, true);

        // SMTP2GO API v3 response format
        if ($httpCode !== 200) {
            $errorMsg = $result['data']['error'] ?? $result['error'] ?? 'HTTP Error ' . $httpCode;
            throw new \Exception("SMTP2GO API error: $errorMsg");
        }

        // Check for error in response
        if (isset($result['data']['error_code']) && $result['data']['error_code'] !== 'SUCCESS') {
            $errorMsg = $result['data']['error'] ?? 'Unknown error';
            throw new \Exception("SMTP2GO API error: $errorMsg");
        }

        // Also check for errors at top level
        if (isset($result['error'])) {
            throw new \Exception("SMTP2GO API error: " . $result['error']);
        }

        if (!empty($result['data']['email_id'])) {
            $rid = $result['request_id'] ?? '';
            error_log(
                'SMTP2GO accepted email_id=' . $result['data']['email_id'] . ($rid !== '' ? ' request_id=' . $rid : '')
            );
        }

        return $result;
    }

    /**
     * Send bulk emails (rate limited)
     */
    public function sendBulk($recipients, $subject, $body)
    {
        $results = [];
        $batchSize = 50;
        $delay = 60; // 1 minute between batches

        $batches = array_chunk($recipients, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $recipient) {
                try {
                    $result = $this->sendEmail($recipient['email'], $subject, $body);
                    $results[] = ['email' => $recipient['email'], 'success' => true];
                } catch (\Exception $e) {
                    Logger::error("Failed to send email to {$recipient['email']}: " . $e->getMessage());
                    $results[] = ['email' => $recipient['email'], 'success' => false, 'error' => $e->getMessage()];
                }
            }

            // Wait between batches (except last batch)
            if ($batchIndex < count($batches) - 1) {
                sleep($delay);
            }
        }

        return $results;
    }

    /**
     * Test email configuration
     */
    public function testConnection()
    {
        try {
            // Try to send a test email to ourselves
            $testEmail = $this->fromEmail;
            if (!$testEmail) {
                throw new \Exception("From email not configured");
            }

            $result = $this->sendEmail(
                $testEmail,
                'SMTP2GO Test Email',
                '<p>This is a test email from Headcount Events platform.</p>'
            );

            return ['success' => true, 'message' => 'Test email sent successfully'];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
