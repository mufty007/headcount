<?php

namespace Headcount\Models;

use Headcount\Core\Database;

/**
 * RSVP Model
 * Handles database operations for RSVPs
 */
class RSVP
{
    private $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Find RSVP by ID
     */
    public function find($id)
    {
        $sql = "SELECT * FROM rsvps WHERE id = :id";
        return $this->db->queryOne($sql, ['id' => $id]);
    }

    /**
     * Find RSVP by event and user
     */
    public function findByEventAndUser($eventId, $userId)
    {
        $sql = "SELECT * FROM rsvps 
                WHERE event_id = :event_id AND user_id = :user_id";
        return $this->db->queryOne($sql, [
            'event_id' => $eventId,
            'user_id' => $userId
        ]);
    }

    /**
     * Create or update RSVP
     */
    public function createOrUpdate($eventId, $userId, $status, $notes = null)
    {
        $existing = $this->findByEventAndUser($eventId, $userId);

        if ($existing) {
            $this->db->update('rsvps', $existing['id'], [
                'status' => $status,
                'notes' => $notes
            ]);
            return $this->find($existing['id']);
        } else {
            $data = [
                'event_id' => $eventId,
                'user_id' => $userId,
                'status' => $status,
                'notes' => $notes
            ];
            $id = $this->db->insert('rsvps', $data);
            return $this->find($id);
        }
    }

    /**
     * Get RSVP counts for event
     */
    public function getCounts($eventId)
    {
        $sql = "SELECT status, COUNT(*) as count 
                FROM rsvps 
                WHERE event_id = :event_id 
                GROUP BY status";
        
        $results = $this->db->query($sql, ['event_id' => $eventId]);
        
        $counts = ['yes' => 0, 'no' => 0, 'maybe' => 0];
        foreach ($results as $row) {
            $counts[$row['status']] = (int) $row['count'];
        }
        
        return $counts;
    }

    /**
     * Get RSVPs for event
     */
    public function getEventRSVPs($eventId, $status = null)
    {
        $sql = "SELECT r.*, u.first_name, u.last_name, u.email, u.phone 
                FROM rsvps r
                JOIN users u ON r.user_id = u.id
                WHERE r.event_id = :event_id";
        
        $params = ['event_id' => $eventId];
        
        if ($status) {
            $sql .= " AND r.status = :status";
            $params['status'] = $status;
        }
        
        $sql .= " ORDER BY r.created_at DESC";
        
        return $this->db->query($sql, $params);
    }
}
