<?php

namespace Headcount\Helpers;

/**
 * Default IANA timezone when an organization has none set (Indianapolis, IN).
 * Use resolve() so empty/invalid org values fall back consistently.
 */
final class OrgTimeZone
{
    public const FALLBACK_IANA = 'America/Indiana/Indianapolis';

    public static function resolve(?string $orgTimezone): string
    {
        $t = trim((string) $orgTimezone);
        if ($t === '') {
            return self::FALLBACK_IANA;
        }
        try {
            new \DateTimeZone($t);

            return $t;
        } catch (\Throwable $e) {
            return self::FALLBACK_IANA;
        }
    }

    /**
     * Current instant in the organization's timezone.
     */
    public static function now(?string $orgTimezone): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::resolve($orgTimezone)));
    }

    /**
     * Today's calendar date (Y-m-d) in the organization's timezone.
     */
    public static function todayYmd(?string $orgTimezone): string
    {
        return self::now($orgTimezone)->format('Y-m-d');
    }

    /**
     * Add days to an org-local Y-m-d date string.
     */
    public static function addDaysYmd(string $ymd, int $days, ?string $orgTimezone): string
    {
        $tz = self::resolve($orgTimezone);
        $dt = new \DateTimeImmutable($ymd, new \DateTimeZone($tz));
        return $dt->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }
}
