<?php

namespace Headcount\Models;

use Headcount\Helpers\Database;

/**
 * Attendance Model
 */
class Attendance
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find attendance record by ID
     */
    public function find($id)
    {
        $sql = "SELECT a.*, 
                u.first_name, u.last_name, u.email, u.phone,
                e.title as event_title, e.event_date, e.start_time
                FROM attendance a
                JOIN users u ON a.user_id = u.id
                JOIN events e ON a.event_id = e.id
                WHERE a.id = :id LIMIT 1";
        return $this->db->queryOne($sql, ['id' => $id]);
    }

    /**
     * Check if user is checked in for event
     */
    public function isCheckedIn($eventId, $userId)
    {
        $slot = '';
        try {
            if ($this->db->hasColumn('attendance', 'family_member_id')) {
                $slot = ' AND IFNULL(family_member_id, 0) = 0';
            }
        } catch (\Exception $e) {
            $slot = '';
        }
        $sql = "SELECT id FROM attendance WHERE event_id = :event_id AND user_id = :user_id{$slot} LIMIT 1";
        $result = $this->db->queryOne($sql, [
            'event_id' => $eventId,
            'user_id' => $userId
        ]);
        return !empty($result);
    }

    /**
     * Create attendance record
     */
    public function create($data)
    {
        $insertData = [
            'event_id' => $data['event_id'],
            'user_id' => $data['user_id'],
            'checked_in_by' => $data['checked_in_by'],
            'payment_status' => $data['payment_status'] ?? 'free',
            'payment_intent_id' => $data['payment_intent_id'] ?? null,
            'amount_paid' => isset($data['amount_paid']) ? (float)$data['amount_paid'] : null,
            'notes' => $data['notes'] ?? null,
        ];

        $id = $this->db->insert('attendance', $insertData);
        return $this->find($id);
    }

    /**
     * Delete attendance record (undo check-in)
     */
    public function delete($eventId, $userId)
    {
        $slot = '';
        try {
            if ($this->db->hasColumn('attendance', 'family_member_id')) {
                $slot = ' AND IFNULL(family_member_id, 0) = 0';
            }
        } catch (\Exception $e) {
            $slot = '';
        }
        $sql = "DELETE FROM attendance WHERE event_id = :event_id AND user_id = :user_id{$slot}";
        return $this->db->execute($sql, [
            'event_id' => $eventId,
            'user_id' => $userId
        ]);
    }

    /**
     * Find attendance by event and user
     */
    public function findByEventAndUser($eventId, $userId)
    {
        $slot = '';
        try {
            if ($this->db->hasColumn('attendance', 'family_member_id')) {
                $slot = ' AND IFNULL(family_member_id, 0) = 0';
            }
        } catch (\Exception $e) {
            $slot = '';
        }
        $sql = "SELECT * FROM attendance WHERE event_id = :event_id AND user_id = :user_id{$slot} LIMIT 1";
        return $this->db->queryOne($sql, [
            'event_id' => $eventId,
            'user_id' => $userId
        ]);
    }

    /**
     * Get all attendance for an event
     */
    public function getByEvent($eventId)
    {
        $sql = "SELECT a.*, 
                u.first_name, u.last_name, u.email, u.phone
                FROM attendance a
                JOIN users u ON a.user_id = u.id
                WHERE a.event_id = :event_id
                ORDER BY a.checked_in_at ASC";
        return $this->db->query($sql, ['event_id' => $eventId]);
    }

    /**
     * Get attendance count for event
     */
    public function getCount($eventId)
    {
        $sql = "SELECT COUNT(*) as count FROM attendance WHERE event_id = :event_id";
        $result = $this->db->queryOne($sql, ['event_id' => $eventId]);
        return (int)$result['count'];
    }

    /**
     * Get user's attendance history
     */
    public function getByUser($userId, $limit = 50, $offset = 0)
    {
        $sql = "SELECT a.*, 
                e.title as event_title, e.event_date, e.start_time, e.location
                FROM attendance a
                JOIN events e ON a.event_id = e.id
                WHERE a.user_id = :user_id
                ORDER BY e.event_date DESC, e.start_time DESC
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        
        return $this->db->query($sql, [
            'user_id' => $userId
        ]);
    }

    /**
     * Get attendance statistics for user
     */
    public function getUserStats($userId)
    {
        $sql = "SELECT 
                COUNT(*) as total_events,
                MIN(e.event_date) as first_event,
                MAX(e.event_date) as last_event
                FROM attendance a
                JOIN events e ON a.event_id = e.id
                WHERE a.user_id = :user_id";
        
        return $this->db->queryOne($sql, ['user_id' => $userId]);
    }

    /**
     * Update payment status
     */
    public function updatePaymentStatus($eventId, $userId, $status, $paymentIntentId = null, $amount = null)
    {
        $sql = "UPDATE attendance 
                SET payment_status = :status,
                    payment_intent_id = :payment_intent_id,
                    amount_paid = :amount
                WHERE event_id = :event_id AND user_id = :user_id";
        
        return $this->db->execute($sql, [
            'event_id' => $eventId,
            'user_id' => $userId,
            'status' => $status,
            'payment_intent_id' => $paymentIntentId,
            'amount' => $amount
        ]);
    }
}
