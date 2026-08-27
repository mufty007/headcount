<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use InvalidArgumentException;
use RuntimeException;

/**
 * Organization owners (is_super_admin) and selected request approvers.
 */
class OwnerService
{
    public const MAX_OWNERS = 3;

    private Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function hasApproverColumn(): bool
    {
        try {
            return $this->db->hasColumn('users', 'can_approve_requests');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function countOwners(int $organizationId): int
    {
        $row = $this->db->queryOne(
            "SELECT COUNT(*) AS c FROM users
             WHERE organization_id = :org AND role = 'admin' AND is_super_admin = 1 AND status = 'active'",
            ['org' => $organizationId]
        );
        return (int) ($row['c'] ?? 0);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listApprovers(int $organizationId): array
    {
        $hasCol = $this->hasApproverColumn();
        $sql = "SELECT id, email, first_name, last_name
                FROM users
                WHERE organization_id = :org
                  AND role = 'admin'
                  AND is_super_admin = 1
                  AND status = 'active'
                  AND email IS NOT NULL AND email != ''";
        if ($hasCol) {
            $sql .= ' AND can_approve_requests = 1';
        }
        $rows = $this->db->query($sql, ['org' => $organizationId]) ?: [];
        $out = [];
        foreach ($rows as $u) {
            $out[] = [
                'id' => (int) $u['id'],
                'email' => (string) $u['email'],
                'first_name' => (string) ($u['first_name'] ?? ''),
                'last_name' => (string) ($u['last_name'] ?? ''),
            ];
        }
        return $out;
    }

    public function userIsApprover(array $user): bool
    {
        if (empty($user['is_super_admin'])) {
            return false;
        }
        if (!$this->hasApproverColumn()) {
            return true;
        }
        return !empty($user['can_approve_requests']);
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function promote(int $organizationId, int $userId): array
    {
        $target = $this->requireAdmin($organizationId, $userId);
        if (!empty($target['is_super_admin'])) {
            return ['success' => false, 'message' => 'This user is already an organization owner.'];
        }
        if ($this->countOwners($organizationId) >= self::MAX_OWNERS) {
            return ['success' => false, 'message' => 'An organization can have at most ' . self::MAX_OWNERS . ' owners.'];
        }

        $fields = ['is_super_admin' => 1];
        if ($this->hasApproverColumn()) {
            $fields['can_approve_requests'] = 0;
        }
        $this->db->execute(
            'UPDATE users SET ' . implode(', ', array_map(static fn ($k) => "$k = :$k", array_keys($fields)))
            . ' WHERE id = :id AND organization_id = :org',
            $fields + ['id' => $userId, 'org' => $organizationId]
        );

        return ['success' => true, 'message' => 'This administrator is now an organization owner.'];
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function demote(int $organizationId, int $userId): array
    {
        $target = $this->requireAdmin($organizationId, $userId);
        if (empty($target['is_super_admin'])) {
            return ['success' => false, 'message' => 'This user is not an organization owner.'];
        }
        if ($this->countOwners($organizationId) <= 1) {
            return ['success' => false, 'message' => 'Cannot remove the last organization owner.'];
        }

        $sql = 'UPDATE users SET is_super_admin = 0';
        $params = ['id' => $userId, 'org' => $organizationId];
        if ($this->hasApproverColumn()) {
            $sql .= ', can_approve_requests = 0';
        }
        $sql .= ' WHERE id = :id AND organization_id = :org';
        $this->db->execute($sql, $params);

        return ['success' => true, 'message' => 'Owner status removed. This user remains an administrator.'];
    }

    /**
     * @return array{success:bool,message:string}
     */
    public function setApprover(int $organizationId, int $userId, bool $enabled): array
    {
        $target = $this->requireAdmin($organizationId, $userId);
        if (empty($target['is_super_admin'])) {
            return ['success' => false, 'message' => 'Only owners can be request approvers.'];
        }
        if (!$this->hasApproverColumn()) {
            throw new RuntimeException('can_approve_requests column is missing. Run migration 085.');
        }

        $this->db->execute(
            'UPDATE users SET can_approve_requests = :v WHERE id = :id AND organization_id = :org AND is_super_admin = 1',
            ['v' => $enabled ? 1 : 0, 'id' => $userId, 'org' => $organizationId]
        );

        return [
            'success' => true,
            'message' => $enabled
                ? 'This owner will be notified and can approve event and program requests.'
                : 'This owner will no longer be notified or able to approve requests.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function requireAdmin(int $organizationId, int $userId): array
    {
        if ($userId <= 0) {
            throw new InvalidArgumentException('User ID required');
        }
        $cols = 'id, role, is_super_admin, status';
        if ($this->hasApproverColumn()) {
            $cols .= ', can_approve_requests';
        }
        $target = $this->db->queryOne(
            "SELECT $cols FROM users WHERE id = :id AND organization_id = :org AND role = 'admin'",
            ['id' => $userId, 'org' => $organizationId]
        );
        if (!$target) {
            throw new RuntimeException('Administrator not found');
        }
        return $target;
    }
}
