<?php

namespace Headcount\Services;

use Headcount\Models\Attendance;
use Headcount\Models\Event;
use Headcount\Models\User;
use Headcount\Core\Logger;

/**
 * Attendance Service
 * Handles business logic for attendance and check-ins
 */
class AttendanceService
{
    private $attendanceModel;
    private $eventModel;
    private $userModel;

    public function __construct(Attendance $attendanceModel, Event $eventModel, User $userModel)
    {
        $this->attendanceModel = $attendanceModel;
        $this->eventModel = $eventModel;
        $this->userModel = $userModel;
    }

    /**
     * Search members for event check-in
     */
    public function searchMembersForEvent($eventId, $organizationId, $query)
    {
        if (strlen($query) < 2) {
            return [];
        }

        // Verify event exists and belongs to organization
        $event = $this->eventModel->find($eventId);
        if (!$event || $event['organization_id'] != $organizationId) {
            return [];
        }

        // Search members
        $members = $this->userModel->search($organizationId, $query, 5);

        // Check which ones are already checked in
        $results = [];
        foreach ($members as $member) {
            $isCheckedIn = $this->attendanceModel->isCheckedIn($eventId, $member['id']);
            $member['checked_in'] = $isCheckedIn;
            $results[] = $member;
        }

        return $results;
    }

    /**
     * Record check-in
     */
    public function recordCheckIn($eventId, $userId, $checkedInBy, $organizationId)
    {
        // Validate event exists
        $event = $this->eventModel->find($eventId);
        if (!$event || $event['organization_id'] != $organizationId) {
            return ['success' => false, 'errors' => [['message' => 'Event not found']]];
        }

        // Validate user exists
        $user = $this->userModel->find($userId);
        if (!$user || $user['organization_id'] != $organizationId) {
            return ['success' => false, 'errors' => [['message' => 'User not found']]];
        }

        // Check if already checked in
        if ($this->attendanceModel->isCheckedIn($eventId, $userId)) {
            return ['success' => false, 'errors' => [['message' => 'User already checked in']]];
        }

        try {
            $data = [
                'event_id' => $eventId,
                'user_id' => $userId,
                'checked_in_by' => $checkedInBy,
            ];

            $attendance = $this->attendanceModel->create($data);
            Logger::logCheckIn($eventId, $userId, $checkedInBy);

            return ['success' => true, 'data' => $attendance];
        } catch (\Exception $e) {
            Logger::error("Failed to record check-in: " . $e->getMessage(), $e);
            return ['success' => false, 'errors' => [['message' => 'Failed to record check-in']]];
        }
    }

    /**
     * Bulk check-in
     */
    public function bulkCheckIn($eventId, $userIds, $checkedInBy, $organizationId)
    {
        // Validate event exists
        $event = $this->eventModel->find($eventId);
        if (!$event || $event['organization_id'] != $organizationId) {
            return ['success' => false, 'errors' => [['message' => 'Event not found']]];
        }

        // Limit to 20 members
        if (count($userIds) > 20) {
            return ['success' => false, 'errors' => [['message' => 'Maximum 20 members allowed']]];
        }

        // Validate all users exist
        foreach ($userIds as $userId) {
            $user = $this->userModel->find($userId);
            if (!$user || $user['organization_id'] != $organizationId) {
                return ['success' => false, 'errors' => [['message' => "User {$userId} not found"]]];
            }
        }

        try {
            $created = $this->attendanceModel->bulkCreate($eventId, $userIds, $checkedInBy);
            Logger::info("Bulk check-in: Event {$eventId}, " . count($created) . " members");

            return ['success' => true, 'data' => $created, 'count' => count($created)];
        } catch (\Exception $e) {
            Logger::error("Failed to bulk check-in: " . $e->getMessage(), $e);
            return ['success' => false, 'errors' => [['message' => 'Failed to bulk check-in']]];
        }
    }

    /**
     * Undo check-in
     */
    public function undoCheckIn($eventId, $userId, $organizationId)
    {
        // Validate event exists
        $event = $this->eventModel->find($eventId);
        if (!$event || $event['organization_id'] != $organizationId) {
            return ['success' => false, 'errors' => [['message' => 'Event not found']]];
        }

        // Check if user is checked in
        if (!$this->attendanceModel->isCheckedIn($eventId, $userId)) {
            return ['success' => false, 'errors' => [['message' => 'Check-in record not found']]];
        }

        // Get attendance record
        $attendanceList = $this->attendanceModel->getEventAttendance($eventId);
        $attendance = null;
        foreach ($attendanceList as $record) {
            if ($record['user_id'] == $userId) {
                $attendance = $record;
                break;
            }
        }

        if (!$attendance) {
            return ['success' => false, 'errors' => [['message' => 'Check-in record not found']]];
        }

        // Check if within 5 minutes
        if (!$this->attendanceModel->canUndo($attendance['id'])) {
            return ['success' => false, 'errors' => [['message' => 'Cannot undo check-in after 5 minutes']]];
        }

        try {
            $this->attendanceModel->delete($attendance['id']);
            Logger::info("Check-in undone: Event {$eventId}, User {$userId}");

            return ['success' => true];
        } catch (\Exception $e) {
            Logger::error("Failed to undo check-in: " . $e->getMessage(), $e);
            return ['success' => false, 'errors' => [['message' => 'Failed to undo check-in']]];
        }
    }

    /**
     * Get event attendance
     */
    public function getEventAttendance($eventId, $organizationId)
    {
        $event = $this->eventModel->find($eventId);
        if (!$event || $event['organization_id'] != $organizationId) {
            return [];
        }

        return $this->attendanceModel->getEventAttendance($eventId);
    }
}
