<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Organization public API key generation and verification (hashed at rest).
 */
class OrganizationApiKeyService
{
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function storeKey(Database $db, int $organizationId, string $apiKey): void
    {
        $prefix = substr($apiKey, 0, 8);
        $hash = password_hash($apiKey, PASSWORD_DEFAULT);
        $db->execute(
            'UPDATE organizations SET api_key_hash = ?, api_key_prefix = ? WHERE id = ?',
            [$hash, $prefix, $organizationId]
        );
    }

    /**
     * Verify API key and return organization row (id, name) or null.
     *
     * @return array{id: int, name?: string}|null
     */
    public static function verifyKey(Database $db, string $apiKey): ?array
    {
        $apiKey = trim($apiKey);
        if ($apiKey === '') {
            return null;
        }

        $prefix = substr($apiKey, 0, 8);
        if ($prefix === '') {
            return null;
        }

        $candidates = $db->query(
            'SELECT id, name, api_key_hash FROM organizations WHERE api_key_prefix = ? AND api_key_hash IS NOT NULL',
            [$prefix]
        );

        foreach ($candidates as $org) {
            if (password_verify($apiKey, $org['api_key_hash'])) {
                return ['id' => (int) $org['id'], 'name' => $org['name'] ?? ''];
            }
        }

        // Legacy plaintext migration (one-time per key)
        if ($db->hasColumn('organizations', 'api_key')) {
            $legacy = $db->queryOne(
                'SELECT id, name FROM organizations WHERE api_key = ? AND api_key IS NOT NULL AND api_key != ?',
                [$apiKey, '']
            );
            if ($legacy) {
                self::storeKey($db, (int) $legacy['id'], $apiKey);
                $db->execute('UPDATE organizations SET api_key = NULL WHERE id = ?', [(int) $legacy['id']]);
                return ['id' => (int) $legacy['id'], 'name' => $legacy['name'] ?? ''];
            }
        }

        return null;
    }

    public static function hasApiKey(Database $db, int $organizationId): bool
    {
        $row = $db->queryOne(
            'SELECT api_key_hash FROM organizations WHERE id = ?',
            [$organizationId]
        );
        return !empty($row['api_key_hash']);
    }
}
