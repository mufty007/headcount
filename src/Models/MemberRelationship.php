<?php

namespace Headcount\Models;

use Headcount\Helpers\Database;

/**
 * MemberRelationship Model
 * Manages family relationships between members
 */
class MemberRelationship
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Create a new relationship
     */
    public function create($data)
    {
        $insertData = [
            'member_id' => $data['member_id'],
            'related_member_id' => $data['related_member_id'],
            'relationship_type' => $data['relationship_type'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ];

        $id = $this->db->insert('member_relationships', $insertData);
        return $this->find($id);
    }

    /**
     * Find relationship by ID
     */
    public function find($id)
    {
        $sql = "SELECT mr.*, 
                       u1.first_name as member_first_name, 
                       u1.last_name as member_last_name,
                       u2.first_name as related_first_name, 
                       u2.last_name as related_last_name
                FROM member_relationships mr
                LEFT JOIN users u1 ON mr.member_id = u1.id
                LEFT JOIN users u2 ON mr.related_member_id = u2.id
                WHERE mr.id = :id 
                LIMIT 1";
        return $this->db->queryOne($sql, ['id' => $id]);
    }

    /**
     * Get all relationships for a specific member
     */
    public function findByMember($memberId)
    {
        $sql = "SELECT mr.*, 
                       u.first_name as related_first_name, 
                       u.last_name as related_last_name,
                       u.email as related_email,
                       u.phone as related_phone
                FROM member_relationships mr
                LEFT JOIN users u ON mr.related_member_id = u.id
                WHERE mr.member_id = :member_id
                ORDER BY mr.relationship_type, u.first_name, u.last_name";
        return $this->db->query($sql, ['member_id' => $memberId]);
    }

    /**
     * Check if a relationship exists
     */
    public function exists($memberId, $relatedMemberId, $relationshipType)
    {
        $sql = "SELECT id FROM member_relationships 
                WHERE member_id = :member_id 
                AND related_member_id = :related_member_id 
                AND relationship_type = :type
                LIMIT 1";
        $result = $this->db->queryOne($sql, [
            'member_id' => $memberId,
            'related_member_id' => $relatedMemberId,
            'type' => $relationshipType
        ]);
        return !empty($result);
    }

    /**
     * Delete a relationship by ID
     */
    public function delete($id)
    {
        return $this->db->delete('member_relationships', $id, 'id', false);
    }

    /**
     * Delete relationship between two members
     */
    public function deleteByMembers($memberId, $relatedMemberId, $relationshipType = null)
    {
        $sql = "DELETE FROM member_relationships 
                WHERE member_id = :member_id 
                AND related_member_id = :related_member_id";
        
        $params = [
            'member_id' => $memberId,
            'related_member_id' => $relatedMemberId
        ];

        if ($relationshipType) {
            $sql .= " AND relationship_type = :type";
            $params['type'] = $relationshipType;
        }

        return $this->db->execute($sql, $params);
    }

    /**
     * Get all family members (direct relationships only)
     */
    public function getFamilyMembers($memberId)
    {
        $sql = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.phone,
                       mr.relationship_type
                FROM member_relationships mr
                JOIN users u ON mr.related_member_id = u.id
                WHERE mr.member_id = :member_id
                ORDER BY mr.relationship_type, u.first_name, u.last_name";
        return $this->db->query($sql, ['member_id' => $memberId]);
    }

    /**
     * Get count of relationships for a member
     */
    public function countByMember($memberId)
    {
        $sql = "SELECT COUNT(*) as count 
                FROM member_relationships 
                WHERE member_id = :member_id";
        $result = $this->db->queryOne($sql, ['member_id' => $memberId]);
        return (int)$result['count'];
    }
}
