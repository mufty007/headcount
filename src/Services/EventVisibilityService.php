<?php

namespace Headcount\Services;

use Headcount\Helpers\Database;

/**
 * Published event visibility for portal/public surfaces.
 */
class EventVisibilityService
{
    public const PUBLIC = 'public';
    public const INTERNAL = 'internal';
    public const INVITE_ONLY = 'invite_only';

    /** @return list<string> */
    public static function allowedValues(): array
    {
        return [self::PUBLIC, self::INTERNAL, self::INVITE_ONLY];
    }

    public static function normalize(?string $raw): string
    {
        $v = strtolower(trim((string) $raw));
        if (!in_array($v, self::allowedValues(), true)) {
            return self::PUBLIC;
        }

        return $v;
    }

    public static function columnExists(Database $db): bool
    {
        try {
            return $db->hasColumn('events', 'visibility');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Visibility string from an event row (defaults public if column absent).
     *
     * @param array<string,mixed> $event
     */
    public static function fromEventRow(array $event): string
    {
        return self::normalize($event['visibility'] ?? self::PUBLIC);
    }

    /**
     * Whether a logged-in portal member may see this published event in lists or detail.
     *
     * @param array<string,mixed> $event Published event row including visibility when migrated.
     */
    public static function portalMemberMayViewPublishedEvent(Database $db, array $event, ?int $memberId): bool
    {
        if (($event['status'] ?? '') !== 'published') {
            return false;
        }
        $v = self::fromEventRow($event);
        if ($v === self::INTERNAL) {
            return false;
        }
        if ($v === self::PUBLIC) {
            return true;
        }
        if ($v !== self::INVITE_ONLY) {
            return true;
        }
        if (!$memberId) {
            return false;
        }
        $inviteSvc = new EventInviteService();

        return $inviteSvc->isUserInvited($db, (int) ($event['id'] ?? 0), $memberId);
    }

    /**
     * Member RSVP (portal): blocked for internal and invite-only without invite.
     *
     * @param array<string,mixed> $event Published event row.
     */
    public static function memberMayRsvp(Database $db, array $event, int $userId): bool
    {
        if (($event['status'] ?? '') !== 'published') {
            return false;
        }
        $v = self::fromEventRow($event);
        if ($v === self::INTERNAL) {
            return false;
        }
        if ($v === self::PUBLIC) {
            return true;
        }
        if ($v === self::INVITE_ONLY) {
            $inviteSvc = new EventInviteService();

            return $inviteSvc->isUserInvited($db, (int) ($event['id'] ?? 0), $userId);
        }

        return true;
    }

    /** Guest / unauthenticated RSVP: only truly public events. */
    public static function guestRsvpAllowed(array $event): bool
    {
        if (($event['status'] ?? '') !== 'published') {
            return false;
        }

        return self::fromEventRow($event) === self::PUBLIC;
    }
}
