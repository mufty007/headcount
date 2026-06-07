<?php

namespace Headcount\Controllers;

use Headcount\Services\MemberService;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Helpers\Utilities;

/**
 * Member Controller
 */
class MemberController
{
    private $memberService;

    public function __construct()
    {
        $this->memberService = new MemberService();
    }

    /**
     * List members
     */
    public function index($filters = [], $page = 1)
    {
        AuthMiddleware::requireAdmin();
        
        $organizationId = AuthMiddleware::getOrganizationId();
        $result = $this->memberService->getMembers($organizationId, $filters, $page);

        if (self::isApiRequest()) {
            Utilities::jsonResponse(true, $result, 'Members retrieved successfully');
        }

        return $result;
    }

    /**
     * Get single member
     */
    public function show($id)
    {
        AuthMiddleware::requireAdmin();
        
        try {
            $member = $this->memberService->getMember($id);
            
            // Verify member belongs to organization
            if ($member['organization_id'] != AuthMiddleware::getOrganizationId()) {
                throw new \Exception('Member not found', 404);
            }

            if (self::isApiRequest()) {
                Utilities::jsonResponse(true, $member, 'Member retrieved successfully');
            }

            return $member;
        } catch (\Exception $e) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
            }
            throw $e;
        }
    }

    /**
     * Create member
     */
    public function create($data)
    {
        try {
            AuthMiddleware::requireAdmin();
            
            $data['organization_id'] = AuthMiddleware::getOrganizationId();

            $member = $this->memberService->createMember($data);

            if (self::isApiRequest()) {
                Utilities::jsonResponse(true, $member, 'Member created successfully');
            }

            return $member;
        } catch (\Exception $e) {
            // Provide user-friendly error messages
            $errorMessage = $e->getMessage();
            $statusCode = $e->getCode() ?: 500;
            
            // Handle specific error cases
            if ($statusCode == 409 || strpos($errorMessage, 'already exists') !== false) {
                $statusCode = 400; // Bad Request for duplicate entries
            }
            
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, $errorMessage, [$errorMessage], $statusCode);
            }
            throw $e;
        }
    }

    /**
     * Update member
     */
    public function update($id, $data)
    {
        AuthMiddleware::requireAdmin();
        
        try {
            // Verify member belongs to organization
            $member = $this->memberService->getMember($id);
            if ($member['organization_id'] != AuthMiddleware::getOrganizationId()) {
                throw new \Exception('Member not found', 404);
            }

            $member = $this->memberService->updateMember($id, $data);

            if (self::isApiRequest()) {
                Utilities::jsonResponse(true, $member, 'Member updated successfully');
            }

            return $member;
        } catch (\Exception $e) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
            }
            throw $e;
        }
    }

    /**
     * Delete member
     */
    public function delete($id)
    {
        AuthMiddleware::requireAdmin();
        
        try {
            // Verify member belongs to organization
            $member = $this->memberService->getMember($id);
            if ($member['organization_id'] != AuthMiddleware::getOrganizationId()) {
                throw new \Exception('Member not found', 404);
            }

            $this->memberService->deleteMember($id);

            if (self::isApiRequest()) {
                Utilities::jsonResponse(true, null, 'Member deleted successfully');
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
     * Search members
     */
    public function search($query)
    {
        AuthMiddleware::requireAdmin();
        
        $organizationId = AuthMiddleware::getOrganizationId();

        try {
            $results = $this->memberService->searchMembers($organizationId, $query);
            Utilities::jsonResponse(true, $results, 'Search completed');
        } catch (\Exception $e) {
            Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
        }
    }

    /**
     * Import members from CSV
     */
    public function import($file, $mapping, $options = [])
    {
        AuthMiddleware::requireAdmin();
        
        $organizationId = AuthMiddleware::getOrganizationId();

        try {
            // Validate file upload
            if (!isset($file) || !is_uploaded_file($file['tmp_name'])) {
                throw new \Exception('No file uploaded or invalid file', 400);
            }

            // Check file type
            $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($fileExtension !== 'csv') {
                throw new \Exception('Invalid file type. Please upload a CSV file.', 400);
            }

            // Handle file upload
            $uploadPath = __DIR__ . '/../../uploads/imports/';
            if (!is_dir($uploadPath)) {
                if (!mkdir($uploadPath, 0755, true)) {
                    throw new \Exception('Failed to create upload directory', 500);
                }
            }

            $fileName = uniqid() . '_' . basename($file['name']);
            $filePath = $uploadPath . $fileName;

            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                error_log("Failed to move uploaded file from {$file['tmp_name']} to {$filePath}");
                throw new \Exception('Failed to upload file. Please check server permissions.', 500);
            }

            // Process import
            $results = $this->memberService->importMembers($organizationId, $filePath, $mapping, $options);

            // Clean up uploaded file
            if (file_exists($filePath)) {
                unlink($filePath);
            }

            // Build success message
            $message = "Import completed. ";
            if ($results['success'] > 0) {
                $message .= "Successfully imported {$results['success']} member(s). ";
            }
            if ($results['failed'] > 0) {
                $message .= "{$results['failed']} failed. ";
            }
            if ($results['duplicates'] > 0) {
                $message .= "{$results['duplicates']} duplicate(s). ";
            }

            Utilities::jsonResponse(true, $results, trim($message));
        } catch (\Exception $e) {
            error_log("Import error: " . $e->getMessage() . " | File: " . ($file['name'] ?? 'unknown'));
            Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
        }
    }

    /**
     * Check if request is API request
     */
    private static function isApiRequest()
    {
        return strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false;
    }
}
