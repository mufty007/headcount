<?php

namespace Headcount\Models;

use Headcount\Helpers\Database;

/**
 * ActivityLog Model
 * Tracks all user activities, emails sent, changes, and system events
 */
class ActivityLog
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Create activity log entry
     */
    public function create($data)
    {
        $insertData = [
            'organization_id' => $data['organization_id'],
            'user_id' => $data['user_id'] ?? null,
            'action_type' => $data['action_type'],
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id' => $data['entity_id'] ?? null,
            'description' => $data['description'],
            'metadata' => !empty($data['metadata']) ? json_encode($data['metadata']) : null,
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
        ];

        $id = $this->db->insert('activity_logs', $insertData);
        return $this->find($id);
    }

    /**
     * Find activity log by ID
     */
    public function find($id)
    {
        $sql = "SELECT al.*, 
                       u.first_name, u.last_name, u.email as user_email
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.id = :id 
                LIMIT 1";
        $result = $this->db->queryOne($sql, ['id' => $id]);
        
        if ($result && $result['metadata']) {
            $result['metadata'] = json_decode($result['metadata'], true);
        }
        
        return $result;
    }

    /**
     * Shared WHERE clause + params for list, count, and statistics (keeps cards aligned with the table).
     *
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildListFiltersWhere($organizationId, array $filters): array
    {
        $where = ['al.organization_id = :org_id'];
        $params = ['org_id' => $organizationId];

        if (!empty($filters['action_type'])) {
            $where[] = 'al.action_type = :action_type';
            $params['action_type'] = $filters['action_type'];
        }

        if (!empty($filters['entity_type'])) {
            $where[] = 'al.entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }

        if (!empty($filters['user_id'])) {
            $where[] = 'al.user_id = :user_id';
            $params['user_id'] = $filters['user_id'];
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(al.created_at) >= :date_from';
            $params['date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(al.created_at) <= :date_to';
            $params['date_to'] = $filters['date_to'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(al.description LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        return [implode(' AND ', $where), $params];
    }

    /**
     * Get activity logs for organization with filters
     */
    public function getByOrganization($organizationId, $filters = [], $limit = 50, $offset = 0)
    {
        [$whereClause, $params] = $this->buildListFiltersWhere($organizationId, $filters);
        
        // Cast limit and offset to integers (MySQL doesn't support named parameters for LIMIT/OFFSET)
        $limitInt = (int)$limit;
        $offsetInt = (int)$offset;
        
        $sql = "SELECT al.*, 
                       u.first_name, u.last_name, u.email as user_email
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE {$whereClause}
                ORDER BY al.created_at DESC
                LIMIT $limitInt OFFSET $offsetInt";

        $results = $this->db->query($sql, $params);
        
        // Decode metadata for each result
        foreach ($results as &$result) {
            if ($result['metadata']) {
                $result['metadata'] = json_decode($result['metadata'], true);
            }
        }
        
        return $results;
    }

    /**
     * Count activity logs
     */
    public function count($organizationId, $filters = [])
    {
        [$whereClause, $params] = $this->buildListFiltersWhere($organizationId, $filters);
        $sql = "SELECT COUNT(*) as count 
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE {$whereClause}";
        
        $result = $this->db->queryOne($sql, $params);
        return (int)$result['count'];
    }

    /**
     * Aggregated statistics for stat cards — uses the same filters as getByOrganization / count.
     *
     * @param array<string, mixed> $filters action_type, entity_type, date_from, date_to, search, user_id
     * @return array{total: int, by_type: array<int, array<string, mixed>>, emails_sent: int, user_changes: int}
     */
    public function getStatistics($organizationId, array $filters = [])
    {
        [$whereClause, $params] = $this->buildListFiltersWhere($organizationId, $filters);
        $fromJoin = 'FROM activity_logs al LEFT JOIN users u ON al.user_id = u.id';

        $totalSql = "SELECT COUNT(*) as count {$fromJoin} WHERE {$whereClause}";
        $total = $this->db->queryOne($totalSql, $params);

        $byTypeSql = "SELECT al.action_type, COUNT(*) as count 
                      {$fromJoin}
                      WHERE {$whereClause}
                      GROUP BY al.action_type
                      ORDER BY count DESC";
        $byType = $this->db->query($byTypeSql, $params);

        $emailsSql = "SELECT COUNT(*) as count {$fromJoin} WHERE {$whereClause} AND al.action_type = 'email_sent'";
        $emails = $this->db->queryOne($emailsSql, $params);

        $userChangesSql = "SELECT COUNT(*) as count {$fromJoin} WHERE {$whereClause} AND al.action_type IN ('user_created', 'user_updated', 'user_deleted')";
        $userChanges = $this->db->queryOne($userChangesSql, $params);

        return [
            'total' => (int) ($total['count'] ?? 0),
            'by_type' => is_array($byType) ? $byType : [],
            'emails_sent' => (int) ($emails['count'] ?? 0),
            'user_changes' => (int) ($userChanges['count'] ?? 0),
        ];
    }
}
