<?php

namespace Headcount\Helpers;

use Headcount\Helpers\Database;

/**
 * Notification Helper
 * Utility functions for creating and managing notifications
 */
class NotificationHelper
{
    /**
     * Create a notification
     * 
     * @param int $organizationId Organization ID
     * @param string $type Notification type
     * @param string $title Notification title
     * @param string $message Notification message
     * @param int|null $userId User ID (null for all users in organization)
     * @param string|null $link Optional link URL
     * @return int Notification ID
     */
    public static function create($organizationId, $type, $title, $message, $userId = null, $link = null)
    {
        try {
            $db = Database::getInstance();
            
            $data = [
                'organization_id' => $organizationId,
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'message' => $message,
                'link' => $link,
                'is_read' => 0
            ];
            
            return $db->insert('notifications', $data);
        } catch (\Exception $e) {
            error_log("Failed to create notification: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create notification for new RSVP
     */
    public static function newRSVP($organizationId, $eventId, $userName, $eventTitle)
    {
        return self::create(
            $organizationId,
            'new_rsvp',
            'New RSVP',
            "$userName has RSVP'd to \"$eventTitle\"",
            null, // All admins
            "/admin/?page=events&event_id=$eventId"
        );
    }

    /**
     * Create notification for event reminder
     */
    public static function eventReminder($organizationId, $eventId, $eventTitle, $reminderType = '1day')
    {
        $reminderText = [
            '1week' => 'in 1 week',
            '1day' => 'in 1 day',
            '2hours' => 'in 2 hours'
        ];
        
        return self::create(
            $organizationId,
            'event_reminder',
            'Event Reminder',
            "Event \"$eventTitle\" is " . ($reminderText[$reminderType] ?? 'soon'),
            null,
            "/admin/?page=events&event_id=$eventId"
        );
    }

    /**
     * Create notification for event cancellation
     */
    public static function eventCancelled($organizationId, $eventId, $eventTitle)
    {
        return self::create(
            $organizationId,
            'event_cancelled',
            'Event Cancelled',
            "Event \"$eventTitle\" has been cancelled",
            null,
            "/admin/?page=events&event_id=$eventId"
        );
    }

    /**
     * Create notification for new member
     */
    public static function newMember($organizationId, $memberName)
    {
        return self::create(
            $organizationId,
            'member_added',
            'New Member',
            "$memberName has been added to the system",
            null,
            "/admin/?page=members"
        );
    }

    /**
     * Checklist task assignment for an event lead.
     */
    public static function checklistAssigned($organizationId, $userId, $eventTitle, $storageEventId, $message = null)
    {
        $msg = $message ?? "You have checklist tasks for \"{$eventTitle}\".";
        return self::create(
            $organizationId,
            'checklist_assigned',
            'Event checklist',
            $msg,
            $userId,
            '/admin/?page=event-checklist&event_id=' . (int) $storageEventId
        );
    }

    /**
     * In-app notification for the event-request workflow.
     */
    public static function eventRequest($organizationId, $userId, $title, $message, $link)
    {
        return self::create(
            $organizationId,
            'event_request',
            $title,
            $message,
            $userId,
            $link
        );
    }
}
