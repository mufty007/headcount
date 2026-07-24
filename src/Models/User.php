<?php

namespace Headcount\Models;

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

/**
 * User Model
 */
class User
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find user by ID
     */
    public function find($id)
    {
        $sql = "SELECT * FROM users WHERE id = :id LIMIT 1";
        return $this->db->queryOne($sql, ['id' => $id]);
    }

    /**
     * Find user by email and organization
     */
    public function findByEmail($email, $organizationId)
    {
        $sql = "SELECT * FROM users 
                WHERE email = :email AND organization_id = :org_id 
                LIMIT 1";
        return $this->db->queryOne($sql, [
            'email' => $email,
            'org_id' => $organizationId
        ]);
    }

    /**
     * Create new user
     */
    public function create($data)
    {
        $insertData = [
            'organization_id' => $data['organization_id'],
            'email' => $data['email'],
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'] ?? null,
            'gender' => $data['gender'] ?? null,
            'date_of_birth' => !empty($data['date_of_birth']) ? $data['date_of_birth'] : null,
            'role' => $data['role'] ?? 'member',
            'status' => $data['status'] ?? 'active',
            // Admin / staff creates are treated as verified; portal self-reg passes null explicitly
            'email_verified_at' => array_key_exists('email_verified_at', $data)
                ? $data['email_verified_at']
                : date('Y-m-d H:i:s'),
        ];

        if (!empty($data['password'])) {
            $insertData['password_hash'] = Security::hashPassword($data['password']);
        }

        $id = $this->db->insert('users', $insertData);
        return $this->find($id);
    }

    /**
     * Update user
     */
    public function update($id, $data)
    {
        $updateData = [];

        if (isset($data['email'])) $updateData['email'] = $data['email'];
        if (isset($data['first_name'])) $updateData['first_name'] = $data['first_name'];
        if (isset($data['last_name'])) $updateData['last_name'] = $data['last_name'];
        if (isset($data['phone'])) $updateData['phone'] = $data['phone'];
        if (isset($data['gender'])) $updateData['gender'] = $data['gender'];
        if (isset($data['date_of_birth'])) $updateData['date_of_birth'] = !empty($data['date_of_birth']) ? $data['date_of_birth'] : null;
        if (isset($data['role'])) $updateData['role'] = $data['role'];
        if (isset($data['status'])) $updateData['status'] = $data['status'];
        if (!empty($data['password'])) {
            $updateData['password_hash'] = Security::hashPassword($data['password']);
        }
        // Allow direct password_hash update (for credential generation)
        if (isset($data['password_hash'])) {
            $updateData['password_hash'] = $data['password_hash'];
        }
        // Allow updating failed_login_attempts and locked_until
        if (isset($data['failed_login_attempts'])) {
            $updateData['failed_login_attempts'] = $data['failed_login_attempts'];
        }
        if (isset($data['locked_until'])) {
            $updateData['locked_until'] = $data['locked_until'];
        }

        if (!empty($updateData)) {
            $this->db->update('users', $id, $updateData);
        }

        return $this->find($id);
    }

    /**
     * Update last login
     */
    public function updateLastLogin($id)
    {
        $sql = "UPDATE users SET last_login_at = NOW(), failed_login_attempts = 0, locked_until = NULL WHERE id = :id";
        $this->db->execute($sql, ['id' => $id]);
    }

    /**
     * Increment failed login attempts
     */
    public function incrementFailedAttempts($id)
    {
        $sql = "UPDATE users SET failed_login_attempts = failed_login_attempts + 1 WHERE id = :id";
        $this->db->execute($sql, ['id' => $id]);
    }

    /**
     * Lock user account
     */
    public function lockAccount($id, $duration = 1800)
    {
        $lockUntil = date('Y-m-d H:i:s', time() + $duration);
        $sql = "UPDATE users SET locked_until = :lock_until WHERE id = :id";
        $this->db->execute($sql, ['id' => $id, 'lock_until' => $lockUntil]);
    }

    /**
     * Search users
     */
    public function search($organizationId, $query, $limit = 10)
    {
        $searchTerm = "%{$query}%";
        $sql = "SELECT * FROM users 
                WHERE organization_id = :org_id 
                AND status = 'active'
                AND (first_name LIKE :term OR last_name LIKE :term OR email LIKE :term OR phone LIKE :term)
                ORDER BY last_name, first_name
                LIMIT " . (int)$limit;
        
        return $this->db->query($sql, [
            'org_id' => $organizationId,
            'term' => $searchTerm
        ]);
    }

    /**
     * Get all users for organization
     */
    public function getAll($organizationId, $filters = [], $limit = 50, $offset = 0)
    {
        $where = ["organization_id = :org_id"];
        $params = ['org_id' => $organizationId];

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where[] = "status = :status";
            $params['status'] = $filters['status'];
        } else {
            // Default: exclude deleted unless explicitly requested
            $where[] = "status != 'deleted'";
        }

        if (!empty($filters['role'])) {
            $where[] = "role = :role";
            $params['role'] = $filters['role'];
        }

        $whereClause = implode(' AND ', $where);
        $limitInt = (int)$limit;
        $offsetInt = (int)$offset;
        $sql = "SELECT * FROM users WHERE {$whereClause} ORDER BY last_name, first_name LIMIT $limitInt OFFSET $offsetInt";

        return $this->db->query($sql, $params);
    }

    /**
     * Count users
     */
    public function count($organizationId, $filters = [])
    {
        $where = ["organization_id = :org_id"];
        $params = ['org_id' => $organizationId];

        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $where[] = "status = :status";
            $params['status'] = $filters['status'];
        } else {
            // Default: exclude deleted
            $where[] = "status != 'deleted'";
        }

        if (!empty($filters['role'])) {
            $where[] = "role = :role";
            $params['role'] = $filters['role'];
        }

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) as count FROM users WHERE {$whereClause}";
        
        $result = $this->db->queryOne($sql, $params);
        return (int)$result['count'];
    }

    public function emailExists($email, $organizationId, $excludeId = null)
    {
        $sql = "SELECT id FROM users WHERE email = :email AND organization_id = :org_id";
        $params = ['email' => $email, 'org_id' => $organizationId];

        if ($excludeId) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $sql .= " LIMIT 1";
        $result = $this->db->queryOne($sql, $params);
        return !empty($result);
    }

    public function phoneExists($phone, $organizationId, $excludeId = null)
    {
        // Skip check if phone is empty
        if (empty($phone)) {
            return false;
        }

        $sql = "SELECT id FROM users WHERE phone = :phone AND organization_id = :org_id";
        $params = ['phone' => $phone, 'org_id' => $organizationId];

        if ($excludeId) {
            $sql .= " AND id != :exclude_id";
            $params['exclude_id'] = $excludeId;
        }

        $sql .= " LIMIT 1";
        $result = $this->db->queryOne($sql, $params);
        return !empty($result);
    }

    /**
     * Delete user permanently (Hard delete)
     */
    public function delete($id)
    {
        return $this->db->delete('users', $id, 'id', false);
    }
}
