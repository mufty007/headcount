<?php

namespace Headcount\Models;

use Headcount\Helpers\Database;

/**
 * Category Model
 */
class Category
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Find category by ID
     */
    public function find($id)
    {
        $sql = "SELECT * FROM categories WHERE id = :id LIMIT 1";
        return $this->db->queryOne($sql, ['id' => $id]);
    }

    /**
     * Find category by slug
     */
    public function findBySlug($organizationId, $slug)
    {
        $sql = "SELECT * FROM categories WHERE organization_id = :org_id AND slug = :slug LIMIT 1";
        return $this->db->queryOne($sql, ['org_id' => $organizationId, 'slug' => $slug]);
    }

    /**
     * Create new category
     */
    public function create($data)
    {
        $insertData = [
            'organization_id' => $data['organization_id'],
            'name' => $data['name'],
            'slug' => $data['slug'],
            'description' => $data['description'] ?? null,
            'color' => $data['color'] ?? '#3B82F6',
            'is_active' => isset($data['is_active']) ? (int)$data['is_active'] : 1,
            'sort_order' => isset($data['sort_order']) ? (int)$data['sort_order'] : 0,
        ];

        $id = $this->db->insert('categories', $insertData);
        return $this->find($id);
    }

    /**
     * Update category
     */
    public function update($id, $data)
    {
        $updateData = [];

        if (isset($data['name'])) $updateData['name'] = $data['name'];
        if (isset($data['slug'])) $updateData['slug'] = $data['slug'];
        if (isset($data['description'])) $updateData['description'] = $data['description'];
        if (isset($data['color'])) $updateData['color'] = $data['color'];
        if (isset($data['is_active'])) $updateData['is_active'] = (int)$data['is_active'];
        if (isset($data['sort_order'])) $updateData['sort_order'] = (int)$data['sort_order'];

        if (!empty($updateData)) {
            $this->db->update('categories', $id, $updateData);
        }

        return $this->find($id);
    }

    /**
     * Get all categories for organization
     */
    public function getAll($organizationId, $activeOnly = false)
    {
        $where = ["organization_id = :org_id"];
        $params = ['org_id' => $organizationId];

        if ($activeOnly) {
            $where[] = "is_active = 1";
        }

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT * FROM categories WHERE {$whereClause} ORDER BY sort_order ASC, name ASC";

        return $this->db->query($sql, $params);
    }

    /**
     * Delete category
     */
    public function delete($id)
    {
        $sql = "DELETE FROM categories WHERE id = :id";
        $this->db->query($sql, ['id' => $id]);
        return true;
    }

    /**
     * Count categories
     */
    public function count($organizationId, $activeOnly = false)
    {
        $where = ["organization_id = :org_id"];
        $params = ['org_id' => $organizationId];

        if ($activeOnly) {
            $where[] = "is_active = 1";
        }

        $whereClause = implode(' AND ', $where);
        $sql = "SELECT COUNT(*) as count FROM categories WHERE {$whereClause}";

        $result = $this->db->queryOne($sql, $params);
        return (int)$result['count'];
    }
}
