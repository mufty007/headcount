<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

/**
 * Remember Token Service
 * Handles remember me token creation, validation, and cleanup
 */
class RememberTokenService
{
    private $db;
    private const TOKEN_EXPIRY_DAYS = 30;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Create a remember token for a user
     * 
     * @param int $userId User ID
     * @param string $tokenType 'admin' or 'portal'
     * @return string Plain text token (to be stored in cookie)
     */
    public function createToken($userId, $tokenType = 'admin')
    {
        // Generate a secure random token
        $token = Security::generateToken(64);
        
        // Hash the token for storage
        $tokenHash = password_hash($token, PASSWORD_DEFAULT);
        
        // Calculate expiration date
        $expiresAt = date('Y-m-d H:i:s', time() + (self::TOKEN_EXPIRY_DAYS * 24 * 60 * 60));
        
        // Store token in database
        $this->db->insert('remember_tokens', [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'last_used_at' => date('Y-m-d H:i:s')
        ]);
        
        return $token;
    }

    /**
     * Validate a remember token
     * 
     * @param string $token Plain text token from cookie
     * @param string $tokenType 'admin' or 'portal'
     * @return array|null User data if valid, null if invalid
     */
    public function validateToken($token, $tokenType = 'admin')
    {
        if (empty($token)) {
            return null;
        }

        // Get all tokens for this token hash (we need to check all since we can't reverse hash)
        // This is less efficient but necessary for security
        // In production, consider storing a lookup token separately
        $tokens = $this->db->query(
            "SELECT rt.*, u.id as user_id, u.organization_id, u.first_name, u.last_name, 
                    u.email, u.role, u.status
             FROM remember_tokens rt
             JOIN users u ON rt.user_id = u.id
             WHERE rt.expires_at > NOW() 
             AND u.status = 'active'
             ORDER BY rt.created_at DESC"
        );

        foreach ($tokens as $tokenRecord) {
            // Verify token matches
            if (password_verify($token, $tokenRecord['token_hash'])) {
                // Check if token is expired
                if (strtotime($tokenRecord['expires_at']) < time()) {
                    // Delete expired token
                    $this->db->query(
                        "DELETE FROM remember_tokens WHERE id = :id",
                        ['id' => $tokenRecord['id']]
                    );
                    return null;
                }

                // Update last used timestamp
                $this->db->update('remember_tokens', $tokenRecord['id'], [
                    'last_used_at' => date('Y-m-d H:i:s')
                ]);

                // Rotate token (security best practice)
                $newToken = $this->rotateToken($tokenRecord['id'], $token);

                return [
                    'user_id' => $tokenRecord['user_id'],
                    'organization_id' => $tokenRecord['organization_id'],
                    'first_name' => $tokenRecord['first_name'],
                    'last_name' => $tokenRecord['last_name'],
                    'email' => $tokenRecord['email'],
                    'role' => $tokenRecord['role'],
                    'new_token' => $newToken // Return new token to update cookie
                ];
            }
        }

        return null;
    }

    /**
     * Rotate token (create new token, delete old one)
     * 
     * @param int $tokenId Old token ID
     * @param string $oldToken Old plain text token
     * @return string New plain text token
     */
    private function rotateToken($tokenId, $oldToken)
    {
        // Get user ID from old token
        $tokenRecord = $this->db->queryOne(
            "SELECT user_id FROM remember_tokens WHERE id = :id",
            ['id' => $tokenId]
        );

        if (!$tokenRecord) {
            return null;
        }

        // Create new token
        $newToken = $this->createToken($tokenRecord['user_id']);

        // Delete old token
        $this->revokeToken($oldToken);

        return $newToken;
    }

    /**
     * Revoke a remember token
     * 
     * @param string $token Plain text token
     * @return bool Success
     */
    public function revokeToken($token)
    {
        if (empty($token)) {
            return false;
        }

        // Find and delete token
        $tokens = $this->db->query(
            "SELECT id, token_hash FROM remember_tokens"
        );

        foreach ($tokens as $tokenRecord) {
            if (password_verify($token, $tokenRecord['token_hash'])) {
                $this->db->query(
                    "DELETE FROM remember_tokens WHERE id = :id",
                    ['id' => $tokenRecord['id']]
                );
                return true;
            }
        }

        return false;
    }

    /**
     * Revoke all tokens for a user
     * 
     * @param int $userId User ID
     * @return bool Success
     */
    public function revokeAllUserTokens($userId)
    {
        $this->db->query(
            "DELETE FROM remember_tokens WHERE user_id = :user_id",
            ['user_id' => $userId]
        );
        return true;
    }

    /**
     * Cleanup expired tokens
     * Should be called periodically (e.g., via cron)
     * 
     * @return int Number of tokens deleted
     */
    public function cleanupExpiredTokens()
    {
        $result = $this->db->query(
            "DELETE FROM remember_tokens WHERE expires_at < NOW()"
        );
        
        // Return count (approximate)
        return $this->db->queryOne(
            "SELECT COUNT(*) as count FROM remember_tokens WHERE expires_at < NOW()"
        )['count'] ?? 0;
    }
}
