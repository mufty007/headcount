<?php

namespace Headcount\Controllers;

use Headcount\Models\Category;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Helpers\Utilities;

/**
 * Category Controller
 */
class CategoryController
{
    private $categoryModel;

    public function __construct()
    {
        $this->categoryModel = new Category();
    }

    /**
     * List categories
     */
    public function index()
    {
        AuthMiddleware::requireAdmin();
        
        $organizationId = AuthMiddleware::getOrganizationId();
        // Check for active_only parameter in query string
        $activeOnly = isset($_GET['active_only']) && ($_GET['active_only'] === '1' || $_GET['active_only'] === 'true');
        $categories = $this->categoryModel->getAll($organizationId, $activeOnly);

        if (self::isApiRequest()) {
            Utilities::jsonResponse(true, ['categories' => $categories], 'Categories retrieved successfully');
        }

        return $categories;
    }

    /**
     * Get single category
     */
    public function show($id)
    {
        AuthMiddleware::requireAdmin();
        
        try {
            $category = $this->categoryModel->find($id);
            
            if (!$category) {
                throw new \Exception('Category not found', 404);
            }

            // Verify category belongs to organization
            if ($category['organization_id'] != AuthMiddleware::getOrganizationId()) {
                throw new \Exception('Category not found', 404);
            }

            if (self::isApiRequest()) {
                Utilities::jsonResponse(true, $category, 'Category retrieved successfully');
            }

            return $category;
        } catch (\Exception $e) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
            }
            throw $e;
        }
    }

    /**
     * Create category
     */
    public function create($data)
    {
        AuthMiddleware::requireAdmin();
        
        $organizationId = AuthMiddleware::getOrganizationId();
        
        // Generate slug from name if not provided
        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = $this->generateSlug($data['name'], $organizationId);
        }

        $data['organization_id'] = $organizationId;

        try {
            // Check if slug already exists
            $existing = $this->categoryModel->findBySlug($organizationId, $data['slug']);
            if ($existing) {
                throw new \Exception('A category with this name already exists', 400);
            }

            $category = $this->categoryModel->create($data);

            if (self::isApiRequest()) {
                Utilities::jsonResponse(true, $category, 'Category created successfully');
            }

            return $category;
        } catch (\Exception $e) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
            }
            throw $e;
        }
    }

    /**
     * Update category
     */
    public function update($id, $data)
    {
        AuthMiddleware::requireAdmin();
        
        try {
            // Verify category belongs to organization
            $category = $this->categoryModel->find($id);
            if (!$category || $category['organization_id'] != AuthMiddleware::getOrganizationId()) {
                throw new \Exception('Category not found', 404);
            }

            // If slug is being changed, check for conflicts
            if (isset($data['slug']) && $data['slug'] !== $category['slug']) {
                $existing = $this->categoryModel->findBySlug(AuthMiddleware::getOrganizationId(), $data['slug']);
                if ($existing && $existing['id'] != $id) {
                    throw new \Exception('A category with this name already exists', 400);
                }
            }

            // Generate slug from name if name changed but slug not provided
            if (isset($data['name']) && !isset($data['slug'])) {
                $data['slug'] = $this->generateSlug($data['name'], AuthMiddleware::getOrganizationId());
            }

            $category = $this->categoryModel->update($id, $data);

            if (self::isApiRequest()) {
                Utilities::jsonResponse(true, $category, 'Category updated successfully');
            }

            return $category;
        } catch (\Exception $e) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
            }
            throw $e;
        }
    }

    /**
     * Delete category
     */
    public function delete($id)
    {
        AuthMiddleware::requireAdmin();
        
        try {
            // Verify category belongs to organization
            $category = $this->categoryModel->find($id);
            if (!$category || $category['organization_id'] != AuthMiddleware::getOrganizationId()) {
                throw new \Exception('Category not found', 404);
            }

            $this->categoryModel->delete($id);

            if (self::isApiRequest()) {
                Utilities::jsonResponse(true, null, 'Category deleted successfully');
            }

            return ['success' => true];
        } catch (\Exception $e) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
            }
            throw $e;
        }
    }

    /**
     * Generate slug from name
     */
    private function generateSlug($name, $organizationId)
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        // Ensure uniqueness
        $baseSlug = $slug;
        $counter = 1;
        while ($this->categoryModel->findBySlug($organizationId, $slug)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Check if request is API request
     */
    private static function isApiRequest()
    {
        return strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
    }
}
