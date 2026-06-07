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
}
