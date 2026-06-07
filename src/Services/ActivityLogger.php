<?php

namespace Headcount\Services;

use Headcount\Models\ActivityLog;

/**
 * Activity Logger Service
 * Helper service for logging activities throughout the application
 */
class ActivityLogger
{
    private $activityLog;
    private $organizationId;
    private $userId;
    private $ipAddress;
    private $userAgent;

    public function __construct($organizationId = null, $userId = null)
    {
        $this->activityLog = new ActivityLog();
        $this->organizationId = $organizationId;
        $this->userId = $userId;
        $this->ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    }

    /**
     * Set organization ID
     */
    public function setOrganizationId($organizationId)
    {
        $this->organizationId = $organizationId;
        return $this;
    }

    /**
     * Set user ID
     */
    public function setUserId($userId)
    {
        $this->userId = $userId;
        return $this;
    }

    /**
     * Log an activity
     */
    public function log($actionType, $description, $entityType = null, $entityId = null, $metadata = [])
    {
        if (!$this->organizationId) {
            return false;
        }

        $data = [
            'organization_id' => $this->organizationId,
            'user_id' => $this->userId,
            'action_type' => $actionType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $description,
            'metadata' => $metadata,
            'ip_address' => $this->ipAddress,
            'user_agent' => $this->userAgent,
        ];

        return $this->activityLog->create($data);
    }

    /**
     * Log email sent
     */
    public function logEmailSent($toEmail, $subject, $emailType = null, $eventId = null, $userId = null, $status = 'sent')
    {
        return $this->log(
            'email_sent',
            "Email sent to {$toEmail}: {$subject}",
            'email',
            null,
            [
                'to_email' => $toEmail,
                'subject' => $subject,
                'email_type' => $emailType,
                'event_id' => $eventId,
                'recipient_user_id' => $userId,
                'status' => $status,
            ]
        );
    }

    /**
     * Log user created
     */
    public function logUserCreated($userId, $userName, $metadata = [])
    {
        return $this->log(
            'user_created',
            "User created: {$userName}",
            'user',
            $userId,
            array_merge(['user_name' => $userName], $metadata)
        );
    }

    /**
     * Log user updated
     */
    public function logUserUpdated($userId, $userName, $changes = [])
    {
        return $this->log(
            'user_updated',
            "User updated: {$userName}",
            'user',
            $userId,
            ['user_name' => $userName, 'changes' => $changes]
        );
    }

    /**
     * Log user deleted
     */
    public function logUserDeleted($userId, $userName)
    {
        return $this->log(
            'user_deleted',
            "User deleted: {$userName}",
            'user',
            $userId,
            ['user_name' => $userName]
        );
    }

    /**
     * Log event created
     */
    public function logEventCreated($eventId, $eventTitle)
    {
        return $this->log(
            'event_created',
            "Event created: {$eventTitle}",
            'event',
            $eventId,
            ['event_title' => $eventTitle]
        );
    }

    /**
     * Log event updated
     */
    public function logEventUpdated($eventId, $eventTitle, $changes = [])
    {
        return $this->log(
            'event_updated',
            "Event updated: {$eventTitle}",
            'event',
            $eventId,
            ['event_title' => $eventTitle, 'changes' => $changes]
        );
    }

    /**
     * Log check-in
     */
    public function logCheckIn($eventId, $userId, $userName, $eventTitle = null)
    {
        return $this->log(
            'checkin',
            "Check-in: {$userName}" . ($eventTitle ? " for {$eventTitle}" : ''),
            'attendance',
            null,
            [
                'event_id' => $eventId,
                'user_id' => $userId,
                'user_name' => $userName,
                'event_title' => $eventTitle,
            ]
        );
    }

    /**
     * Log staff override: mark checked in after the event or outside live window.
     */
    public function logCheckinOverride($eventId, $userId, $userName, $reason, $eventTitle = null, $checkedInAt = null)
    {
        return $this->log(
            'checkin_override',
            'Check-in correction: ' . $userName . ($eventTitle ? " ({$eventTitle})" : ''),
            'attendance',
            null,
            [
                'event_id' => $eventId,
                'user_id' => $userId,
                'user_name' => $userName,
                'event_title' => $eventTitle,
                'reason' => $reason,
                'checked_in_at' => $checkedInAt,
                'override' => true,
            ]
        );
    }

    /**
     * Log staff override: remove check-in record.
     */
    public function logUndoCheckinOverride($eventId, $userId, $userName, $reason, $eventTitle = null)
    {
        return $this->log(
            'undo_checkin_override',
            'Removed check-in: ' . $userName . ($eventTitle ? " ({$eventTitle})" : ''),
            'attendance',
            null,
            [
                'event_id' => $eventId,
                'user_id' => $userId,
                'user_name' => $userName,
                'event_title' => $eventTitle,
                'reason' => $reason,
                'override' => true,
            ]
        );
    }

    /**
     * Log staff override: change check-in timestamp.
     */
    public function logCheckinTimeUpdated($eventId, $userId, $userName, $reason, $checkedInAt, $eventTitle = null)
    {
        return $this->log(
            'checkin_time_updated',
            'Updated check-in time: ' . $userName . ($eventTitle ? " ({$eventTitle})" : ''),
            'attendance',
            null,
            [
                'event_id' => $eventId,
                'user_id' => $userId,
                'user_name' => $userName,
                'event_title' => $eventTitle,
                'reason' => $reason,
                'checked_in_at' => $checkedInAt,
                'override' => true,
            ]
        );
    }

    /**
     * Log payment
     */
    public function logPayment($paymentId, $eventId, $userId, $amount, $status = 'paid')
    {
        return $this->log(
            'payment',
            "Payment {$status}: \${$amount}",
            'payment',
            $paymentId,
            [
                'event_id' => $eventId,
                'user_id' => $userId,
                'amount' => $amount,
                'status' => $status,
            ]
        );
    }

    /**
     * Log login
     */
    public function logLogin($userId, $userName)
    {
        return $this->log(
            'login',
            "User logged in: {$userName}",
            'user',
            $userId,
            ['user_name' => $userName]
        );
    }

    /**
     * Log logout
     */
    public function logLogout($userId, $userName)
    {
        return $this->log(
            'logout',
            "User logged out: {$userName}",
            'user',
            $userId,
            ['user_name' => $userName]
        );
    }

    /**
     * Log member import
     */
    public function logMemberImport($count, $filename = null)
    {
        return $this->log(
            'member_import',
            "Members imported: {$count} members",
            null,
            null,
            [
                'count' => $count,
                'filename' => $filename,
            ]
        );
    }

    /**
     * Log cash payment recorded at check-in
     */
    public function logCashPayment($eventId, $userId, $amount, $paymentId)
    {
        return $this->log(
            'cash_payment_recorded',
            "Cash payment recorded: \${$amount}",
            'payment',
            $paymentId,
            [
                'event_id' => $eventId,
                'user_id' => $userId,
                'amount' => $amount,
            ]
        );
    }

    /**
     * Log cash payment updated (edit)
     */
    public function logCashPaymentUpdated($paymentId, $eventId, $userId, $amount, $previousAmount = null)
    {
        $desc = "Cash payment updated: \${$amount}";
        if ($previousAmount !== null) {
            $desc = "Cash payment updated: \${$previousAmount} → \${$amount}";
        }
        return $this->log(
            'cash_payment_updated',
            $desc,
            'payment',
            $paymentId,
            [
                'event_id' => $eventId,
                'user_id' => $userId,
                'amount' => $amount,
                'previous_amount' => $previousAmount,
            ]
        );
    }

    /**
     * Log cash payment deleted
     */
    public function logCashPaymentDeleted($paymentId, $eventId, $userId, $amount)
    {
        return $this->log(
            'cash_payment_deleted',
            "Cash payment deleted: \${$amount}",
            'payment',
            $paymentId,
            [
                'event_id' => $eventId,
                'user_id' => $userId,
                'amount' => $amount,
            ]
        );
    }

    /**
     * Log refund initiated by admin/coordinator
     */
    public function logRefundInitiated($paymentId, $amount, $reason, $userId = null)
    {
        return $this->log(
            'refund_initiated',
            "Refund initiated: \${$amount}" . ($reason ? " — {$reason}" : ''),
            'payment',
            $paymentId,
            [
                'refund_amount' => $amount,
                'refund_reason' => $reason,
                'initiated_by_user_id' => $userId ?? $this->userId,
            ]
        );
    }
}
