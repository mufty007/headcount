<?php
/**
 * Kiosk / digital-signage data helpers (shared by public/portal/kiosk.php and
 * public/api/portal/kiosk-events.php). Public, read-only, organization-scoped.
 *
 * No authentication: a kiosk is a lobby/TV display addressed by the org's public
 * slug. Only PUBLISHED events in the requested forward window are ever exposed.
 */

use Headcount\Helpers\Database;

if (!function_exists('headcount_kiosk_org_by_slug')) {
    /**
     * Look up a public organization by its slug. Returns the display-safe subset
     * (no secrets) or null if not found.
     *
     * @return array<string,mixed>|null
     */
    function headcount_kiosk_org_by_slug(Database $db, string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }
        $row = $db->queryOne(
            "SELECT id, name, slug, logo_path, primary_color, timezone, date_format, time_format
             FROM organizations WHERE slug = :slug LIMIT 1",
            ['slug' => $slug]
        );
        return $row ?: null;
    }
}

if (!function_exists('headcount_kiosk_settings')) {
    /**
     * Read an organization's kiosk display settings, falling back to safe
     * defaults if the 069 migration has not been applied yet.
     *
     * @param array<string,mixed> $orgRow An organizations row (may already hold the columns)
     * @return array{enabled:bool,mode:string,days:int,interval:int}
     */
    function headcount_kiosk_settings(Database $db, array $orgRow): array
    {
        $defaults = ['enabled' => true, 'mode' => 'board', 'days' => 7, 'interval' => 8];

        // Prefer values already present on the passed row.
        $row = $orgRow;
        $hasCols = array_key_exists('kiosk_mode', $row);

        // If not present, fetch them — but only if the column exists.
        if (!$hasCols && !empty($orgRow['id'])) {
            $colExists = method_exists($db, 'hasColumn') ? $db->hasColumn('organizations', 'kiosk_mode') : false;
            if ($colExists) {
                $fetched = $db->queryOne(
                    "SELECT kiosk_enabled, kiosk_mode, kiosk_days, kiosk_interval FROM organizations WHERE id = :id",
                    ['id' => (int) $orgRow['id']]
                );
                if ($fetched) {
                    $row = $fetched;
                    $hasCols = true;
                }
            }
        }

        if (!$hasCols) {
            return $defaults;
        }

        $mode = ($row['kiosk_mode'] ?? 'board') === 'slideshow' ? 'slideshow' : 'board';
        return [
            'enabled'  => !array_key_exists('kiosk_enabled', $row) ? true : (bool) $row['kiosk_enabled'],
            'mode'     => $mode,
            'days'     => isset($row['kiosk_days']) ? max(1, min(60, (int) $row['kiosk_days'])) : 7,
            'interval' => isset($row['kiosk_interval']) ? max(3, min(60, (int) $row['kiosk_interval'])) : 8,
        ];
    }
}

if (!function_exists('headcount_kiosk_banner_url')) {
    /**
     * Build an absolute, browser-loadable URL for an event banner. Mirrors the
     * logic in public/api/portal/events.php so the kiosk shows the same images.
     */
    function headcount_kiosk_banner_url(?string $bannerPath): ?string
    {
        $bannerPath = trim((string) $bannerPath);
        if ($bannerPath === '') {
            return null;
        }
        if (filter_var($bannerPath, FILTER_VALIDATE_URL)) {
            return $bannerPath;
        }
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Derive the web base for /api/image.php from the ACTUAL request path so it
        // matches however the kiosk page / feed was reached (direct file or router).
        $reqPath = str_replace('\\', '/', (string) parse_url($_SERVER['REQUEST_URI'] ?? '/portal/kiosk.php', PHP_URL_PATH));
        // Strip trailing "/portal/<file>" or "/api/portal/<file>" to reach the public base.
        $base = preg_replace('#/(?:api/)?portal/[^/]*$#', '', $reqPath);
        $base = rtrim((string) $base, '/');
        $imagePath = ltrim($bannerPath, '/');
        return $protocol . '://' . $host . $base . '/api/image.php?path=' . urlencode($imagePath);
    }
}

if (!function_exists('headcount_kiosk_load_events')) {
    /**
     * Load published events for an organization within a forward day window,
     * normalized for signage display.
     *
     * @return array<int,array<string,mixed>>
     */
    function headcount_kiosk_load_events(Database $db, int $orgId, string $timezone, int $days = 7): array
    {
        $days = max(1, min(60, $days));

        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }

        // "Today" and the window end are computed in the org's timezone so the
        // board matches the wall clock where the screen lives.
        $today = new \DateTime('now', $tz);
        $startDate = $today->format('Y-m-d');
        $endDate = (clone $today)->modify('+' . $days . ' days')->format('Y-m-d');

        // banner_image may be absent on very old schemas; guard defensively.
        $hasBanner = method_exists($db, 'hasColumn') ? $db->hasColumn('events', 'banner_image') : true;
        $bannerCol = $hasBanner ? 'banner_image' : 'NULL AS banner_image';

        $rows = $db->query(
            "SELECT id, title, event_date, start_time, end_time, location, category, capacity, $bannerCol
             FROM events
             WHERE organization_id = :org
               AND status = 'published'
               AND event_date BETWEEN :start AND :end
             ORDER BY event_date ASC, start_time ASC",
            ['org' => $orgId, 'start' => $startDate, 'end' => $endDate]
        );

        $todayStr = $today->format('Y-m-d');
        $tomorrowStr = (clone $today)->modify('+1 day')->format('Y-m-d');

        $out = [];
        foreach ($rows as $r) {
            $dateStr = (string) $r['event_date'];
            $startTime = $r['start_time'] ?? null;

            try {
                $dt = new \DateTime($dateStr . ' ' . ($startTime ?: '00:00:00'), $tz);
            } catch (\Throwable $e) {
                $dt = null;
            }

            // Friendly day label: Today / Tomorrow / weekday name.
            if ($dateStr === $todayStr) {
                $dayLabel = 'Today';
            } elseif ($dateStr === $tomorrowStr) {
                $dayLabel = 'Tomorrow';
            } else {
                $dayLabel = $dt ? $dt->format('l') : $dateStr;
            }

            $out[] = [
                'id'            => (int) $r['id'],
                'title'         => (string) $r['title'],
                'location'      => $r['location'] !== null ? (string) $r['location'] : '',
                'category'      => $r['category'] !== null ? (string) $r['category'] : '',
                'capacity'      => $r['capacity'] !== null ? (int) $r['capacity'] : null,
                'date_iso'      => $dt ? $dt->format('c') : ($dateStr . 'T00:00:00'),
                'day_label'     => $dayLabel,
                'day_num'       => $dt ? $dt->format('j') : '',
                'month_short'   => $dt ? strtoupper($dt->format('M')) : '',
                'weekday_short' => $dt ? strtoupper($dt->format('D')) : '',
                'date_pretty'   => $dt ? $dt->format('D, M j') : $dateStr,
                'time_pretty'   => $startTime ? (new \DateTime($dateStr . ' ' . $startTime, $tz))->format('g:i A') : 'All day',
                'banner_url'    => headcount_kiosk_banner_url($r['banner_image'] ?? null),
            ];
        }

        return $out;
    }
}
