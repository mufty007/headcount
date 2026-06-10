<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

/**
 * Invited members for invite-only events. Rows are keyed by RSVP source event id
 * (same as {@see EventSeriesHelper::getRsvpSourceEventId}).
 */
class EventInviteService
{
    private $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    public function tableExists(): bool
    {
        try {
            $r = $this->db->queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'event_invites' LIMIT 1"
            );

            return !empty($r);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Event id used for event_invites.event_id (parent when all_sessions RSVPs live on parent).
     */
    public static function inviteStorageEventId(Database $db, int $viewEventId): int
    {
        if ($viewEventId <= 0) {
            return 0;
        }

        return EventSeriesHelper::getRsvpSourceEventId($db, $viewEventId);
    }

    public function isUserInvited(Database $db, int $viewEventId, int $userId): bool
    {
        if ($viewEventId <= 0 || $userId <= 0 || !$this->tableExists()) {
            return false;
        }
        $storageId = self::inviteStorageEventId($db, $viewEventId);
        if ($storageId <= 0) {
            return false;
        }
        $r = $this->db->queryOne(
            'SELECT 1 AS ok FROM event_invites WHERE event_id = ? AND user_id = ? LIMIT 1',
            [$storageId, $userId]
        );

        return !empty($r);
    }

    /**
     * @return list<array{id:int,user_id:int,first_name:string,last_name:string,email:?string,invited_at:?string,note:?string}>
     */
    public function listInvitesForViewEvent(Database $db, int $organizationId, int $viewEventId): array
    {
        if (!$this->tableExists() || $viewEventId <= 0) {
            return [];
        }
        $storageId = self::inviteStorageEventId($db, $viewEventId);
        if ($storageId <= 0) {
            return [];
        }
        $ev = $this->db->queryOne('SELECT organization_id FROM events WHERE id = ?', [$storageId]);
        if (!$ev || (int) ($ev['organization_id'] ?? 0) !== $organizationId) {
            return [];
        }

        return $this->db->query(
            "SELECT ei.id, ei.user_id, ei.invited_at, ei.note,
                    u.first_name, u.last_name, u.email, u.password_hash
             FROM event_invites ei
             INNER JOIN users u ON u.id = ei.user_id
             WHERE ei.event_id = :eid AND ei.organization_id = :org
             ORDER BY u.last_name ASC, u.first_name ASC, ei.id ASC",
            ['eid' => $storageId, 'org' => $organizationId]
        ) ?: [];
    }

    /**
     * Add invites (skips invalid users / duplicates). Shared by UI and future bulk import.
     *
     * @param list<int> $userIds
     * @return array{added:int,skipped:int}
     */
    public function addInvitesForViewEvent(
        Database $db,
        int $organizationId,
        int $viewEventId,
        array $userIds,
        ?int $invitedByUserId,
        ?string $note = null
    ): array {
        if (!$this->tableExists() || $viewEventId <= 0) {
            return ['added' => 0, 'skipped' => 0];
        }
        $storageId = self::inviteStorageEventId($db, $viewEventId);
        if ($storageId <= 0) {
            return ['added' => 0, 'skipped' => 0];
        }
        $ev = $this->db->queryOne('SELECT id, organization_id FROM events WHERE id = ?', [$storageId]);
        if (!$ev || (int) ($ev['organization_id'] ?? 0) !== $organizationId) {
            return ['added' => 0, 'skipped' => 0];
        }

        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        $added = 0;
        $skipped = 0;
        $noteTrim = $note !== null && trim($note) !== '' ? substr(trim($note), 0, 500) : null;

        foreach ($userIds as $uid) {
            if ($uid <= 0) {
                $skipped++;
                continue;
            }
            $u = $this->db->queryOne(
                "SELECT id
                 FROM users
                 WHERE id = ?
                   AND organization_id = ?
                   AND role = 'member'
                   AND status = 'active'",
                [$uid, $organizationId]
            );
            if (!$u) {
                $skipped++;
                continue;
            }
            try {
                $this->db->insert('event_invites', [
                    'organization_id' => $organizationId,
                    'event_id' => $storageId,
                    'user_id' => $uid,
                    'invited_by' => $invitedByUserId > 0 ? $invitedByUserId : null,
                    'note' => $noteTrim,
                ]);
                $added++;
            } catch (\Throwable $e) {
                $skipped++;
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    public function removeInviteForViewEvent(Database $db, int $organizationId, int $viewEventId, int $inviteRowId): bool
    {
        if (!$this->tableExists() || $inviteRowId <= 0) {
            return false;
        }
        $storageId = self::inviteStorageEventId($db, $viewEventId);
        if ($storageId <= 0) {
            return false;
        }
        $exists = $this->db->queryOne(
            'SELECT id FROM event_invites WHERE id = ? AND event_id = ? AND organization_id = ? LIMIT 1',
            [$inviteRowId, $storageId, $organizationId]
        );
        if (!$exists) {
            return false;
        }
        $this->db->execute(
            'DELETE FROM event_invites WHERE id = ? AND event_id = ? AND organization_id = ?',
            [$inviteRowId, $storageId, $organizationId]
        );

        return true;
    }

    /**
     * Invite by email — creates stub member if needed, adds invite row, returns user info for email.
     *
     * @return array{success:bool,message?:string,added?:int,skipped?:int,user?:array,needs_profile?:bool,invite?:array}
     */
    public function inviteGuestByEmailForViewEvent(
        Database $db,
        int $organizationId,
        int $viewEventId,
        string $email,
        string $firstName,
        string $lastName,
        ?int $invitedByUserId
    ): array {
        if (!$this->tableExists() || $viewEventId <= 0) {
            return ['success' => false, 'message' => 'Invites are not available.'];
        }

        $email = trim(strtolower($email));
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        if ($firstName === '' || $lastName === '') {
            return ['success' => false, 'message' => 'First name and last name are required.'];
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Please enter a valid email address.'];
        }

        $storageId = self::inviteStorageEventId($db, $viewEventId);
        if ($storageId <= 0) {
            return ['success' => false, 'message' => 'Event not found.'];
        }
        $ev = $this->db->queryOne('SELECT id, organization_id FROM events WHERE id = ?', [$storageId]);
        if (!$ev || (int) ($ev['organization_id'] ?? 0) !== $organizationId) {
            return ['success' => false, 'message' => 'Event not found.'];
        }

        $user = $this->db->queryOne(
            "SELECT * FROM users WHERE organization_id = ? AND email = ? AND status != 'deleted'",
            [$organizationId, $email]
        );
        $needsProfile = true;
        if ($user) {
            $role = (string) ($user['role'] ?? 'member');
            if (in_array($role, ['admin', 'coordinator'], true)) {
                return ['success' => false, 'message' => 'That email belongs to a staff account and cannot be invited as a guest.'];
            }
            $userId = (int) $user['id'];
            $needsProfile = empty($user['password_hash']);
            if ($firstName !== '' && empty($user['first_name'])) {
                $this->db->update('users', $userId, ['first_name' => $firstName]);
            }
            if ($lastName !== '' && empty($user['last_name'])) {
                $this->db->update('users', $userId, ['last_name' => $lastName]);
            }
            $user = $this->db->queryOne('SELECT * FROM users WHERE id = ?', [$userId]);
        } else {
            $userId = (int) $this->db->insert('users', [
                'organization_id' => $organizationId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => null,
                'password_hash' => null,
                'role' => 'member',
                'status' => 'active',
                'qr_code_secret' => Security::generateToken(32),
                'email_preferences' => json_encode([
                    'event_announcements' => true,
                    'event_reminders' => true,
                    'rsvp_confirmations' => true,
                    'payment_receipts' => true,
                ]),
                'communication_preferences' => json_encode([
                    'email_enabled' => true,
                    'sms_enabled' => false,
                ]),
            ]);
            $user = $this->db->queryOne('SELECT * FROM users WHERE id = ?', [$userId]);
            $needsProfile = true;
        }

        $existing = $this->db->queryOne(
            'SELECT id FROM event_invites WHERE event_id = ? AND user_id = ? LIMIT 1',
            [$storageId, $userId]
        );
        if ($existing) {
            return [
                'success' => true,
                'added' => 0,
                'skipped' => 1,
                'message' => 'This person is already on the invite list.',
                'user' => $user,
                'needs_profile' => $needsProfile,
            ];
        }

        try {
            $inviteId = (int) $this->db->insert('event_invites', [
                'organization_id' => $organizationId,
                'event_id' => $storageId,
                'user_id' => $userId,
                'invited_by' => $invitedByUserId > 0 ? $invitedByUserId : null,
                'note' => null,
            ]);
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => 'Could not add invite.'];
        }

        return [
            'success' => true,
            'added' => 1,
            'skipped' => 0,
            'user' => $user,
            'needs_profile' => $needsProfile,
            'invite' => ['id' => $inviteId, 'user_id' => $userId],
        ];
    }
}

