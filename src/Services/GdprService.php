<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Core\SecurityLogger;

/**
 * GDPR Compliance Service
 * Handles user data export and deletion for GDPR compliance
 */
class GdprService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Export user data (Right to Access / Right to Portability)
     */
    public function exportUserData($userId)
    {
        SecurityLogger::logDataAccess('user', $userId);
        
        $user = $this->getUserData($userId);
        $attendance = $this->getUserAttendance($userId);
        $payments = $this->getUserPayments($userId);
        $rsvps = $this->getUserRSVPs($userId);
        
        return [
            'user' => $user,
            'attendance' => $attendance,
            'payments' => $payments,
            'rsvps' => $rsvps,
            'exported_at' => date('Y-m-d H:i:s'),
            'format' => 'json'
        ];
    }

    /**
     * Delete user data (Right to Deletion / Right to be Forgotten)
     */
    public function deleteUserData($userId)
    {
        SecurityLogger::logDataModification('user', $userId, 'gdpr_deletion');
        
        $db = $this->db;
        
        // Start transaction
        $db->beginTransaction();
        
        try {
            // Anonymize attendance records (keep for statistics but remove personal data)
            $db->execute(
                "UPDATE attendance SET user_id = NULL, notes = NULL WHERE user_id = :user_id",
                ['user_id' => $userId]
            );
            
            // Anonymize RSVPs
            $db->execute(
                "UPDATE rsvps SET user_id = NULL, notes = NULL WHERE user_id = :user_id",
                ['user_id' => $userId]
            );
            
            // Anonymize payments (keep transaction records but remove personal data)
            $db->execute(
                "UPDATE payments SET user_id = NULL, notes = NULL WHERE user_id = :user_id",
                ['user_id' => $userId]
            );
            
            // Soft delete user - anonymize personal information
            $db->execute(
                "UPDATE users SET 
                    email = CONCAT('deleted_', id, '@deleted.local'),
                    phone = NULL,
                    first_name = 'Deleted',
                    last_name = 'User',
                    status = 'deleted',
                    deleted_at = NOW()
                WHERE id = :user_id",
                ['user_id' => $userId]
            );
            
            $db->commit();
            
            return [
                'success' => true,
                'message' => 'User data has been deleted in accordance with GDPR requirements'
            ];
        } catch (\Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    /**
     * Get user data
     */
    private function getUserData($userId)
    {
        $sql = "SELECT id, email, first_name, last_name, phone, role, status, 
                       created_at, updated_at, last_login
                FROM users WHERE id = :user_id";
        return $this->db->queryOne($sql, ['user_id' => $userId]);
    }

    /**
     * Get user attendance records
     */
    private function getUserAttendance($userId)
    {
        $sql = "SELECT a.*, e.title as event_title, e.event_date
                FROM attendance a
                LEFT JOIN events e ON a.event_id = e.id
                WHERE a.user_id = :user_id
                ORDER BY a.created_at DESC";
        return $this->db->query($sql, ['user_id' => $userId]);
    }

    /**
     * Get user payment records
     */
    private function getUserPayments($userId)
    {
        $sql = "SELECT p.*, e.title as event_title
                FROM payments p
                LEFT JOIN events e ON p.event_id = e.id
                WHERE p.user_id = :user_id
                ORDER BY p.created_at DESC";
        return $this->db->query($sql, ['user_id' => $userId]);
    }

    /**
     * Get user RSVP records
     */
    private function getUserRSVPs($userId)
    {
        $sql = "SELECT r.*, e.title as event_title, e.event_date
                FROM rsvps r
                LEFT JOIN events e ON r.event_id = e.id
                WHERE r.user_id = :user_id
                ORDER BY r.created_at DESC";
        return $this->db->query($sql, ['user_id' => $userId]);
    }

    /**
     * Export user data as JSON
     */
    public function exportUserDataAsJson($userId)
    {
        $data = $this->exportUserData($userId);
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Export user data as CSV
     */
    public function exportUserDataAsCsv($userId)
    {
        $data = $this->exportUserData($userId);
        
        $csv = [];
        $csv[] = ['Field', 'Value'];
        $csv[] = ['User ID', $data['user']['id'] ?? ''];
        $csv[] = ['Email', $data['user']['email'] ?? ''];
        $csv[] = ['First Name', $data['user']['first_name'] ?? ''];
        $csv[] = ['Last Name', $data['user']['last_name'] ?? ''];
        $csv[] = ['Phone', $data['user']['phone'] ?? ''];
        $csv[] = ['Role', $data['user']['role'] ?? ''];
        $csv[] = ['Created At', $data['user']['created_at'] ?? ''];
        $csv[] = ['Last Login', $data['user']['last_login'] ?? ''];
        $csv[] = [];
        $csv[] = ['Attendance Records', count($data['attendance'])];
        $csv[] = ['Payment Records', count($data['payments'])];
        $csv[] = ['RSVP Records', count($data['rsvps'])];
        
        $output = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($output, $row);
        }
        rewind($output);
        $csvContent = stream_get_contents($output);
        fclose($output);
        
        return $csvContent;
    }
}
