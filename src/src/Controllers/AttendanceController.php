<?php

namespace Headcount\Controllers;

use Headcount\Services\AttendanceService;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Helpers\Utilities;

/**
 * Attendance Controller
 */
class AttendanceController
{
    private $attendanceService;

    public function __construct()
    {
        $this->attendanceService = new AttendanceService();
    }

    /**
     * Search members for check-in
     */
    public function search($eventId, $query)
    {
        AuthMiddleware::requireAdminOrCoordinator();
        
        $organizationId = AuthMiddleware::getOrganizationId();

        try {
            $results = $this->attendanceService->searchMembersForEvent($eventId, $organizationId, $query);
            Utilities::jsonResponse(true, $results, 'Search completed');
        } catch (\Exception $e) {
            Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
        }
    }

    /**
     * Check in member
     */
    public function checkIn($eventId, $userId)
    {
        AuthMiddleware::requireAdminOrCoordinator();
        
        $checkedInBy = AuthMiddleware::getUserId();

        try {
            $attendance = $this->attendanceService->checkIn($eventId, $userId, $checkedInBy);
            Utilities::jsonResponse(true, $attendance, 'Member checked in successfully');
        } catch (\Exception $e) {
            Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
        }
    }

    /**
     * Bulk check-in
     */
    public function bulkCheckIn($eventId, $userIds)
    {
        AuthMiddleware::requireAdminOrCoordinator();
        
        $checkedInBy = AuthMiddleware::getUserId();

        try {
            $results = $this->attendanceService->bulkCheckIn($eventId, $userIds, $checkedInBy);
            Utilities::jsonResponse(true, $results, 'Bulk check-in completed');
        } catch (\Exception $e) {
            Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
        }
    }

    /**
     * Undo check-in
     */
    public function undoCheckIn($eventId, $userId)
    {
        AuthMiddleware::requireAdminOrCoordinator();

        try {
            $this->attendanceService->undoCheckIn($eventId, $userId);
            Utilities::jsonResponse(true, null, 'Check-in undone successfully');
        } catch (\Exception $e) {
            Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
        }
    }

    /**
     * Get event attendance
     */
    public function getEventAttendance($eventId)
    {
        AuthMiddleware::requireAdminOrCoordinator();
        
        $organizationId = AuthMiddleware::getOrganizationId();

        try {
            $result = $this->attendanceService->getEventAttendance($eventId, $organizationId);
            
            if (self::isApiRequest()) {
                Utilities::jsonResponse(true, $result, 'Attendance retrieved successfully');
            }

            return $result;
        } catch (\Exception $e) {
            if (self::isApiRequest()) {
                Utilities::jsonResponse(false, null, $e->getMessage(), [], $e->getCode() ?: 500);
            }
            throw $e;
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
