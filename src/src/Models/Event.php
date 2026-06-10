<?php

namespace Headcount\Models;

use Headcount\Helpers\Database;

/**
 * Event Model
 */
class Event
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find event by ID
     */
    public function find($id)
    {
        $sql = "SELECT e.*, u.first_name as creator_first_name, u.last_name as creator_last_name
                FROM events e
                LEFT JOIN users u ON e.created_by = u.id
                WHERE e.id = :id LIMIT 1";
        return $this->db->queryOne($sql, ['id' => $id]);
    }

    /**
     * Create new event
     */
    public function create($data)
    {
        $insertData = [
            'organization_id' => $data['organization_id'],
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'event_date' => $data['event_date'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'] ?? null,
            'location' => $data['location'] ?? null,
            'category' => $data['category'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'is_paid' => isset($data['is_paid']) ? (int)$data['is_paid'] : 0,
            'price' => isset($data['price']) ? (float)$data['price'] : null,
            'capacity' => isset($data['capacity']) ? (int)$data['capacity'] : null,
            'allow_walk_ins' => isset($data['allow_walk_ins']) ? (int)$data['allow_walk_ins'] : 1,
            'require_rsvp' => isset($data['require_rsvp']) ? (int)$data['require_rsvp'] : 0,
            'created_by' => $data['created_by'],
        ];
        if (isset($data['visibility'])) {
            $insertData['visibility'] = $data['visibility'];
        }

        $id = $this->db->insert('events', $insertData);
        return $this->find($id);
    }

    /**
     * Update event
     */
    public function update($id, $data)
    {
        $updateData = [];

        if (isset($data['title'])) $updateData['title'] = $data['title'];
        if (isset($data['description'])) $updateData['description'] = $data['description'];
        if (isset($data['event_date'])) $updateData['event_date'] = $data['event_date'];
        if (isset($data['start_time'])) $updateData['start_time'] = $data['start_time'];
        if (isset($data['end_time'])) $updateData['end_time'] = $data['end_time'];
        if (isset($data['location'])) $updateData['location'] = $data['location'];
        if (isset($data['category'])) $updateData['category'] = $data['category'];
        if (isset($data['status'])) $updateData['status'] = $data['status'];
        if (isset($data['is_paid'])) $updateData['is_paid'] = (int)$data['is_paid'];
        if (isset($data['price'])) $updateData['price'] = (float)$data['price'];
        if (isset($data['capacity'])) $updateData['capacity'] = (int)$data['capacity'];
        if (isset($data['allow_walk_ins'])) $updateData['allow_walk_ins'] = (int)$data['allow_walk_ins'];
        if (isset($data['require_rsvp'])) $updateData['require_rsvp'] = (int)$data['require_rsvp'];
        if (isset($data['visibility'])) $updateData['visibility'] = $data['visibility'];

        if (!empty($updateData)) {
            $this->db->update('events', $id, $updateData);
        }

        return $this->find($id);
    }

    /**
     * Get all events for organization
     */
    public function getAll($organizationId, $filters = [], $limit = 50, $offset = 0)
    {
        $where = ["e.organization_id = :org_id"];
        $params = ['org_id' => $organizationId];

        if (!empty($filters['status'])) {
            $where[] = "e.status = :status";
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = "e.event_date >= :date_from";
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = "e.event_date <= :date_to";
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['category'])) {
            $where[] = "e.category = :category";
            $params['category'] = $filters['category'];
        }

        $whereClause = implode(' AND ', $where);
        $orderBy = $filters['order_by'] ?? 'event_date DESC, start_time ASC';
        
        $sql = "SELECT e.*, 
                (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.id) as attendance_count,
                (SELECT COUNT(*) FROM rsvps r WHERE r.event_id = e.id AND r.status = 'yes') as rsvp_count
                FROM events e
                WHERE {$whereClause}
                ORDER BY {$orderBy}
                LIMIT " . (int)$limit . " OFFSET " . (int)$offset;

        return $this->db->query($sql, $params);
    }

    /**
     * Get upcoming events
     */
    public function getUpcoming($organizationId, $limit = 10)
    {
        $sql = "SELECT e.*, 
                (SELECT COUNT(*) FROM attendance a WHERE a.event_id = e.id) as attendance_count
                FROM events e
                WHERE e.organization_id = :org_id
                AND e.status = 'published'
                AND (e.event_date > CURDATE() OR (e.event_date = CURDATE() AND e.start_time > CURTIME()))
                ORDER BY e.event_date ASC, e.start_time ASC
                LIMIT " . (int)$limit;

        return $this->db->query($sql, [
            'org_id' => $organizationId
        ]);
    }

    /**
     * Count events
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
        $sql = "SELECT COUNT(*) as count FROM events WHERE {$whereClause}";
        
        $result = $this->db->queryOne($sql, $params);
        return (int)$result['count'];
    }

    /**
     * Duplicate event
     */
    public function duplicate($id)
    {
        $event = $this->find($id);
        if (!$event) {
            return null;
        }

        $newData = [
            'organization_id' => $event['organization_id'],
            'title' => $event['title'] . ' (Copy)',
            'description' => $event['description'],
            'event_date' => date('Y-m-d', strtotime('+1 week')), // Default to 1 week from now
            'start_time' => $event['start_time'],
            'end_time' => $event['end_time'],
            'location' => $event['location'],
            'category' => $event['category'],
            'status' => 'draft',
            'is_paid' => $event['is_paid'],
            'price' => $event['price'],
            'capacity' => $event['capacity'],
            'allow_walk_ins' => $event['allow_walk_ins'],
            'require_rsvp' => $event['require_rsvp'],
            'created_by' => $event['created_by'],
        ];
        if (isset($event['visibility'])) {
            $newData['visibility'] = $event['visibility'];
        }

        return $this->create($newData);
    }
}
