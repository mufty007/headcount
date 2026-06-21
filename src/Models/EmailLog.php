<?php

namespace Headcount\Models;

use Headcount\Helpers\Database;

/**
 * EmailLog Model
 */
class EmailLog
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Create email log entry
     */
    /** Allowed values for email_logs.email_type ENUM */
    private static $allowedEmailTypes = ['announcement', 'reminder', 'confirmation', 'receipt', 'cancellation', 'custom', 'event_feedback', 'follow_up'];

    public function create($data)
    {
        $rawType = $data['template'] ?? $data['email_type'] ?? 'custom';
        $emailType = in_array($rawType, self::$allowedEmailTypes, true) ? $rawType : 'custom';

        // Map old field names to new schema field names
        $insertData = [
            'organization_id' => $data['organization_id'],
            'event_id' => $data['event_id'] ?? null,
            'recipient_user_id' => $data['user_id'] ?? $data['recipient_user_id'] ?? null,
            'recipient_email' => $data['to_email'] ?? $data['recipient_email'],
            'subject' => $data['subject'],
            'email_type' => $emailType,
            'status' => $data['status'] ?? 'queued',
            'error_message' => $data['smtp_response'] ?? $data['error_message'] ?? null,
            'sent_at' => $data['sent_at'] ?? null,
        ];
        if (array_key_exists('campaign_id', $data)) {
            $insertData['campaign_id'] = $data['campaign_id'] ?: null;
        }
        if (array_key_exists('program_id', $data) && $this->db->hasColumn('email_logs', 'program_id')) {
            $insertData['program_id'] = $data['program_id'] ? (int) $data['program_id'] : null;
        }

        $id = $this->db->insert('email_logs', $insertData);
        $row = $this->find($id);
        return $row ?: ['id' => $id];
    }

    /**
     * Find email log by ID
     */
    public function find($id)
    {
        $sql = "SELECT * FROM email_logs WHERE id = :id LIMIT 1";
        return $this->db->queryOne($sql, ['id' => $id]);
    }

    /**
     * Update email log status
     */
    public function updateStatus($id, $status, $smtpResponse = null)
    {
        $updateData = [
            'status' => $status,
            'sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
        ];

        if ($smtpResponse !== null) {
            $updateData['error_message'] = $smtpResponse;
        }

        $this->db->update('email_logs', $id, $updateData);
        return $this->find($id);
    }

    /**
     * Update SMTP message id (for webhook correlation)
     */
    public function updateSmtpMessageId($id, $smtpMessageId)
    {
        $this->db->update('email_logs', $id, ['smtp_message_id' => $smtpMessageId]);
        return $this->find($id);
    }

    /**
     * Get email logs for organization
     */
    public function getByOrganization($organizationId, $filters = [], $limit = 50, $offset = 0)
    {
        $where = ["organization_id = :org_id"];
        $params = ['org_id' => $organizationId];

        if (!empty($filters['status'])) {
            $where[] = "status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['event_id'])) {
            $where[] = "event_id = :event_id";
            $params['event_id'] = $filters['event_id'];
        }

        $whereClause = implode(' AND ', $where);
        $limitInt = (int)$limit;
        $offsetInt = (int)$offset;
        $sql = "SELECT * FROM email_logs 
                WHERE {$whereClause}
                ORDER BY created_at DESC
                LIMIT $limitInt OFFSET $offsetInt";

        return $this->db->query($sql, $params);
    }

    /**
     * Count email logs
     */
    public function count($organizationId, $filters = [])
    {
        $where = ["organization_id = :org_id"];
        $params = ['org_id' => $organizationId];

        if (!empty($filters['status'])) {
            $where[] = "status = :status";
            $params['status'] = $filters['status'];
        }

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) as count FROM email_logs WHERE {$whereClause}";
        
        $result = $this->db->queryOne($sql, $params);
        return (int)$result['count'];
    }
}
