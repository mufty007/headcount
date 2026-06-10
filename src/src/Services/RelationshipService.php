<?php

namespace Headcount\Services;

use Headcount\Models\MemberRelationship;
use Headcount\Models\User;
use Headcount\Middleware\AuthMiddleware;

/**
 * RelationshipService
 * Business logic for managing family relationships
 */
class RelationshipService
{
    private $relationshipModel;
    private $userModel;

    // Mapping of relationship types to their inverses
    private $inverseRelationships = [
        'spouse' => 'spouse',
        'parent' => 'child',
        'child' => 'parent',
        'sibling' => 'sibling',
        'guardian' => 'ward',
        'ward' => 'guardian',
        'other' => 'other'
    ];

    public function __construct()
    {
        $this->relationshipModel = new MemberRelationship();
        $this->userModel = new User();
    }

    /**
     * Create a bidirectional relationship
     * Creates both A->B and B->A relationships
     */
    public function createRelationship($memberId, $relatedMemberId, $type, $notes = null)
    {
        // Validate inputs
        $this->validateRelationship($memberId, $relatedMemberId, $type);

        // Get the inverse relationship type
        $inverseType = $this->getInverseType($type);

        // Get current user ID for tracking
        $createdBy = AuthMiddleware::getUserId();

        // Get database instance
        $db = $this->getDb();

        try {
            $db->beginTransaction();

            // Create the primary relationship (A -> B)
            $relationship1 = $this->relationshipModel->create([
                'member_id' => $memberId,
                'related_member_id' => $relatedMemberId,
                'relationship_type' => $type,
                'notes' => $notes,
                'created_by' => $createdBy
            ]);

            // Create the inverse relationship (B -> A)
            $relationship2 = $this->relationshipModel->create([
                'member_id' => $relatedMemberId,
                'related_member_id' => $memberId,
                'relationship_type' => $inverseType,
                'notes' => $notes,
                'created_by' => $createdBy
            ]);

            $db->commit();

            return [
                'primary' => $relationship1,
                'inverse' => $relationship2
            ];
        } catch (\Exception $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * Delete a bidirectional relationship
     * Removes both A->B and B->A relationships
     */
    public function deleteRelationship($memberId, $relatedMemberId)
    {
        // Get database instance
        $db = $this->getDb();
        
        try {
            $db->beginTransaction();

            // Get the relationship to find its type
            $relationships = $this->relationshipModel->findByMember($memberId);
            $relationshipType = null;
            
            foreach ($relationships as $rel) {
                if ($rel['related_member_id'] == $relatedMemberId) {
                    $relationshipType = $rel['relationship_type'];
                    break;
                }
            }

            if (!$relationshipType) {
                throw new \Exception('Relationship not found', 404);
            }

            $inverseType = $this->getInverseType($relationshipType);

            // Delete both directions
            $this->relationshipModel->deleteByMembers($memberId, $relatedMemberId, $relationshipType);
            $this->relationshipModel->deleteByMembers($relatedMemberId, $memberId, $inverseType);

            $db->commit();

            return true;
        } catch (\Exception $e) {
            $db->rollback();
            throw $e;
        }
    }

    /**
     * Get all relationships for a member
     */
    public function getMemberRelationships($memberId)
    {
        return $this->relationshipModel->findByMember($memberId);
    }

    /**
     * Get family network (all connected members)
     * Returns all members connected through any relationship
     */
    public function getFamilyNetwork($memberId, $depth = 2)
    {
        $visited = [];
        $network = [];

        $this->buildFamilyNetwork($memberId, $depth, $visited, $network);

        return $network;
    }

    /**
     * Recursively build family network
     */
    private function buildFamilyNetwork($memberId, $depth, &$visited, &$network)
    {
        if ($depth <= 0 || in_array($memberId, $visited)) {
            return;
        }

        $visited[] = $memberId;
        $relationships = $this->relationshipModel->findByMember($memberId);

        foreach ($relationships as $rel) {
            $relatedId = $rel['related_member_id'];
            
            if (!isset($network[$relatedId])) {
                $network[$relatedId] = [
                    'id' => $relatedId,
                    'first_name' => $rel['related_first_name'],
                    'last_name' => $rel['related_last_name'],
                    'email' => $rel['related_email'],
                    'phone' => $rel['related_phone'],
                    'relationships' => []
                ];
            }

            $network[$relatedId]['relationships'][] = [
                'type' => $rel['relationship_type'],
                'connected_through' => $memberId
            ];

            // Recurse to find extended family
            $this->buildFamilyNetwork($relatedId, $depth - 1, $visited, $network);
        }
    }

    /**
     * Validate relationship creation
     */
    private function validateRelationship($memberId, $relatedMemberId, $type)
    {
        // Check if both members exist
        $member = $this->userModel->find($memberId);
        $relatedMember = $this->userModel->find($relatedMemberId);

        if (!$member) {
            throw new \Exception('Member not found', 404);
        }

        if (!$relatedMember) {
            throw new \Exception('Related member not found', 404);
        }

        // Prevent self-relationships
        if ($memberId == $relatedMemberId) {
            throw new \Exception('Cannot create relationship with self', 400);
        }

        // Check if relationship already exists
        if ($this->relationshipModel->exists($memberId, $relatedMemberId, $type)) {
            throw new \Exception('Relationship already exists', 409);
        }

        // Validate relationship type
        if (!isset($this->inverseRelationships[$type])) {
            throw new \Exception('Invalid relationship type', 400);
        }

        return true;
    }

    /**
     * Get the inverse relationship type
     */
    private function getInverseType($type)
    {
        return $this->inverseRelationships[$type] ?? 'other';
    }

    /**
     * Get database instance
     */
    private function getDb()
    {
        if (!$this->db) {
            $this->db = \Headcount\Helpers\Database::getInstance();
        }
        return $this->db;
    }

    private $db;
}
