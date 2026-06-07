<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

/**
 * QR Code Service
 * Generates and validates QR codes for member check-in
 */
class QRCodeService
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Generate QR code data for a member
     * 
     * @param int $userId User ID
     * @return array QR code data
     */
    public function generateQRCodeData($userId)
    {
        // Check if qr_code_secret column exists
        $hasQrCodeSecret = $this->db->hasColumn('users', 'qr_code_secret');
        
        if ($hasQrCodeSecret) {
            $user = $this->db->queryOne(
                "SELECT id, organization_id, qr_code_secret FROM users WHERE id = :id",
                ['id' => $userId]
            );
        } else {
            // Fallback: query without qr_code_secret column
            $user = $this->db->queryOne(
                "SELECT id, organization_id FROM users WHERE id = :id",
                ['id' => $userId]
            );
            if ($user) {
                $user['qr_code_secret'] = null;
            }
        }

        if (!$user) {
            return null;
        }

        // Generate or retrieve QR code secret
        if (empty($user['qr_code_secret'])) {
            $qrSecret = Security::generateToken(32);
            if ($hasQrCodeSecret) {
                $this->db->update('users', $userId, ['qr_code_secret' => $qrSecret]);
            }
        } else {
            $qrSecret = $user['qr_code_secret'];
        }

        // Create QR code payload (encrypted)
        $payload = [
            'user_id' => $userId,
            'organization_id' => $user['organization_id'],
            'timestamp' => time(),
            'secret' => $qrSecret
        ];

        // Encode as JSON and create a simple hash for validation
        $qrData = base64_encode(json_encode($payload));
        $hash = hash_hmac('sha256', $qrData, $qrSecret);

        return [
            'data' => $qrData,
            'hash' => $hash,
            'full_code' => $qrData . '|' . $hash
        ];
    }

    /**
     * Validate QR code
     * 
     * @param string $qrCode QR code string
     * @return array|null User data if valid, null if invalid
     */
    public function validateQRCode($qrCode)
    {
        if (empty($qrCode)) {
            return null;
        }

        // Split data and hash
        $parts = explode('|', $qrCode);
        if (count($parts) !== 2) {
            return null;
        }

        list($qrData, $hash) = $parts;

        // Decode payload
        $payload = json_decode(base64_decode($qrData), true);
        if (!$payload || !isset($payload['user_id']) || !isset($payload['secret'])) {
            return null;
        }

        // Verify hash
        $expectedHash = hash_hmac('sha256', $qrData, $payload['secret']);
        if (!hash_equals($expectedHash, $hash)) {
            return null;
        }

        // Check if QR code is not too old (24 hours)
        if (isset($payload['timestamp']) && (time() - $payload['timestamp']) > 86400) {
            return null;
        }

        // Check if qr_code_secret column exists
        $hasQrCodeSecret = $this->db->hasColumn('users', 'qr_code_secret');
        
        if ($hasQrCodeSecret) {
            $user = $this->db->queryOne(
                "SELECT id, organization_id, first_name, last_name, email, qr_code_secret 
                 FROM users 
                 WHERE id = :id AND status = 'active'",
                ['id' => $payload['user_id']]
            );
            
            if (!$user || $user['qr_code_secret'] !== $payload['secret']) {
                return null;
            }
        } else {
            // Fallback: query without qr_code_secret column
            $user = $this->db->queryOne(
                "SELECT id, organization_id, first_name, last_name, email 
                 FROM users 
                 WHERE id = :id AND status = 'active'",
                ['id' => $payload['user_id']]
            );
            
            if (!$user) {
                return null;
            }
        }

        // Get family members for this user (if table exists)
        $familyMembers = [];
        try {
            $tableCheck = $this->db->query("SHOW TABLES LIKE 'family_members'");
            if (!empty($tableCheck)) {
                $familyMembers = $this->db->query(
                    "SELECT id, first_name, last_name, linked_user_id, relationship
                     FROM family_members 
                     WHERE parent_user_id = :user_id",
                    ['user_id' => $user['id']]
                );
            }
        } catch (\Exception $e) {
            // Table doesn't exist or error querying, return empty array
            error_log("QRCodeService - Error querying family_members: " . $e->getMessage());
        }

        return [
            'id' => $user['id'],
            'organization_id' => $user['organization_id'],
            'name' => trim($user['first_name'] . ' ' . $user['last_name']),
            'email' => $user['email'],
            'family_members' => $familyMembers
        ];
    }

    /**
     * Generate QR code image URL (for display)
     * Uses a simple API endpoint that generates the QR code
     * 
     * @param int $userId User ID
     * @return string QR code image URL
     */
    public function getQRCodeImageUrl($userId, $baseUrl)
    {
        return $baseUrl . '/api/portal/qr-code/image?user_id=' . $userId;
    }
}
