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
                    " . (method_exists($db, 'hasColumn') && $db->hasColumn('organizations', 'city') ? ', city' : ', NULL AS city') . "
                    " . (method_exists($db, 'hasColumn') && $db->hasColumn('organizations', 'country') ? ', country' : ', NULL AS country') . "
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
        $defaults = ['enabled' => true, 'mode' => 'split', 'days' => 7, 'interval' => 8];

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

        $rawMode = strtolower(trim((string) ($row['kiosk_mode'] ?? 'split')));
        $mode = in_array($rawMode, ['split', 'board', 'slideshow'], true) ? $rawMode : 'split';
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

if (!function_exists('headcount_kiosk_blurb')) {
    function headcount_kiosk_blurb(?string $html, int $max = 180): string
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            return '';
        }
        if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
            return rtrim(mb_substr($text, 0, $max - 1)) . '…';
        }
        if (strlen($text) > $max) {
            return rtrim(substr($text, 0, $max - 1)) . '…';
        }
        return $text;
    }
}

if (!function_exists('headcount_kiosk_when_label')) {
    function headcount_kiosk_when_label(string $dayLabel, ?\DateTimeInterface $dt): string
    {
        $day = strtolower($dayLabel);
        if ($day === 'today') {
            return 'TODAY';
        }
        if ($day === 'tomorrow') {
            return 'TOMORROW';
        }
        return $dt ? strtoupper($dt->format('D, M j')) : strtoupper($dayLabel);
    }
}

if (!function_exists('headcount_kiosk_hue')) {
    function headcount_kiosk_hue(string $seed): int
    {
        return abs(crc32($seed)) % 360;
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
        $hasDesc = method_exists($db, 'hasColumn') ? $db->hasColumn('events', 'description') : true;
        $descCol = $hasDesc ? 'description' : 'NULL AS description';

        $rows = $db->query(
            "SELECT id, title, event_date, start_time, end_time, location, category, capacity, $bannerCol, $descCol
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
                'kind'          => 'event',
                'title'         => (string) $r['title'],
                'blurb'         => headcount_kiosk_blurb($r['description'] ?? ''),
                'location'      => $r['location'] !== null ? (string) $r['location'] : '',
                'category'      => $r['category'] !== null ? (string) $r['category'] : '',
                'capacity'      => $r['capacity'] !== null ? (int) $r['capacity'] : null,
                'date_iso'      => $dt ? $dt->format('c') : ($dateStr . 'T00:00:00'),
                'day_label'     => $dayLabel,
                'when_label'    => headcount_kiosk_when_label($dayLabel, $dt),
                'day_num'       => $dt ? $dt->format('j') : '',
                'month_short'   => $dt ? strtoupper($dt->format('M')) : '',
                'weekday_short' => $dt ? strtoupper($dt->format('D')) : '',
                'date_pretty'   => $dt ? $dt->format('D, M j') : $dateStr,
                'time_pretty'   => $startTime ? (new \DateTime($dateStr . ' ' . $startTime, $tz))->format('g:i A') : 'All day',
                'banner_url'    => headcount_kiosk_banner_url($r['banner_image'] ?? null),
                'hue'           => headcount_kiosk_hue((string) $r['title']),
            ];
        }

        return $out;
    }
}

if (!function_exists('headcount_kiosk_load_program_sessions')) {
    /**
     * Published program sessions in the same forward window as events.
     *
     * @return array<int,array<string,mixed>>
     */
    function headcount_kiosk_load_program_sessions(Database $db, int $orgId, string $timezone, int $days = 7): array
    {
        $days = max(1, min(60, $days));
        if (!method_exists($db, 'tableExists') || !$db->tableExists('program_sessions') || !$db->tableExists('programs')) {
            return [];
        }

        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }

        $today = new \DateTime('now', $tz);
        $startDate = $today->format('Y-m-d');
        $endDate = (clone $today)->modify('+' . $days . ' days')->format('Y-m-d');
        $hasBanner = method_exists($db, 'hasColumn') ? $db->hasColumn('programs', 'banner_image') : true;
        $bannerCol = $hasBanner ? 'p.banner_image' : 'NULL AS banner_image';
        $hasSessionStatus = method_exists($db, 'hasColumn') ? $db->hasColumn('program_sessions', 'status') : false;
        $statusSql = $hasSessionStatus ? "AND s.status = 'scheduled'" : '';

        $hasDesc = method_exists($db, 'hasColumn') ? $db->hasColumn('programs', 'description') : true;
        $descCol = $hasDesc ? 'p.description' : 'NULL AS description';

        $rows = $db->query(
            "SELECT s.id, s.session_date, s.start_time, s.end_time, p.title, p.location, $bannerCol, $descCol
             FROM program_sessions s
             INNER JOIN programs p ON p.id = s.program_id
             WHERE p.organization_id = :org
               AND LOWER(TRIM(p.status)) = 'published'
               AND s.session_date BETWEEN :start AND :end
               $statusSql
             ORDER BY s.session_date ASC, s.start_time ASC",
            ['org' => $orgId, 'start' => $startDate, 'end' => $endDate]
        ) ?: [];

        $todayStr = $today->format('Y-m-d');
        $tomorrowStr = (clone $today)->modify('+1 day')->format('Y-m-d');
        $out = [];
        foreach ($rows as $r) {
            $dateStr = (string) $r['session_date'];
            $startTime = $r['start_time'] ?? null;
            try {
                $dt = new \DateTime($dateStr . ' ' . ($startTime ?: '00:00:00'), $tz);
            } catch (\Throwable $e) {
                $dt = null;
            }
            if ($dateStr === $todayStr) {
                $dayLabel = 'Today';
            } elseif ($dateStr === $tomorrowStr) {
                $dayLabel = 'Tomorrow';
            } else {
                $dayLabel = $dt ? $dt->format('l') : $dateStr;
            }
            $timePretty = 'All day';
            if ($startTime) {
                try {
                    $timePretty = (new \DateTime($dateStr . ' ' . $startTime, $tz))->format('g:i A');
                } catch (\Throwable $e) {
                    $timePretty = (string) $startTime;
                }
            }
            $out[] = [
                'id'            => 'program-' . (int) $r['id'],
                'kind'          => 'program',
                'title'         => (string) $r['title'],
                'blurb'         => headcount_kiosk_blurb($r['description'] ?? ''),
                'location'      => $r['location'] !== null ? (string) $r['location'] : '',
                'category'      => 'Program',
                'capacity'      => null,
                'date_iso'      => $dt ? $dt->format('c') : ($dateStr . 'T00:00:00'),
                'day_label'     => $dayLabel,
                'when_label'    => headcount_kiosk_when_label($dayLabel, $dt),
                'day_num'       => $dt ? $dt->format('j') : '',
                'month_short'   => $dt ? strtoupper($dt->format('M')) : '',
                'weekday_short' => $dt ? strtoupper($dt->format('D')) : '',
                'date_pretty'   => $dt ? $dt->format('D, M j') : $dateStr,
                'time_pretty'   => $timePretty,
                'banner_url'    => headcount_kiosk_banner_url($r['banner_image'] ?? null),
                'hue'           => headcount_kiosk_hue((string) $r['title']),
            ];
        }
        return $out;
    }
}

if (!function_exists('headcount_kiosk_load_items')) {
    /**
     * Combined upcoming events and published program sessions, sorted by start.
     *
     * @return array<int,array<string,mixed>>
     */
    function headcount_kiosk_load_items(Database $db, int $orgId, string $timezone, int $days = 7): array
    {
        $items = array_merge(
            headcount_kiosk_load_events($db, $orgId, $timezone, $days),
            headcount_kiosk_load_program_sessions($db, $orgId, $timezone, $days)
        );
        usort($items, static function ($a, $b) {
            return strcmp((string) ($a['date_iso'] ?? ''), (string) ($b['date_iso'] ?? ''));
        });
        return array_values($items);
    }
}

if (!function_exists('headcount_kiosk_format_prayer_time')) {
    function headcount_kiosk_format_prayer_time(?string $hms): string
    {
        $hms = trim((string) $hms);
        if ($hms === '') {
            return '';
        }
        try {
            $dt = \DateTime::createFromFormat('H:i:s', $hms) ?: \DateTime::createFromFormat('H:i', $hms);
            return $dt ? $dt->format('g:i A') : $hms;
        } catch (\Throwable $e) {
            return $hms;
        }
    }
}

if (!function_exists('headcount_kiosk_prayer_times')) {
    /**
     * Today's Fajr/Sunrise/Dhuhr/Asr/Maghrib/Isha for the org city, cached ~6 hours.
     *
     * @param array<string,mixed> $org
     * @return array{available:bool,note:?string,date:string,timings:list<array{name:string,time:string}>}
     */
    function headcount_kiosk_prayer_times(array $org, string $timezone): array
    {
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Throwable $e) {
            $tz = new \DateTimeZone('UTC');
        }
        $date = (new \DateTime('now', $tz))->format('Y-m-d');
        $empty = [
            'available' => false,
            'note' => 'Set city in Settings',
            'date' => $date,
            'timings' => [],
        ];
        $city = trim((string) ($org['city'] ?? ''));
        $country = trim((string) ($org['country'] ?? ''));
        if ($city === '' || $country === '') {
            return $empty;
        }

        $keys = ['Fajr', 'Sunrise', 'Dhuhr', 'Asr', 'Maghrib', 'Isha'];
        $cacheKey = md5($city . '|' . $country . '|' . $date);
        $cacheFile = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'hc-kiosk-prayer-' . $cacheKey . '.json';
        $cached = null;
        if (is_file($cacheFile) && (time() - (int) filemtime($cacheFile)) < 21600) {
            $raw = @file_get_contents($cacheFile);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (is_array($decoded) && !empty($decoded['timings'])) {
                $cached = $decoded;
            }
        }
        if ($cached === null) {
            $map = \Headcount\Services\PrayerTimesService::timingsByCity($date, $city, $country);
            if (!is_array($map)) {
                return $empty;
            }
            $timings = [];
            foreach ($keys as $name) {
                if (empty($map[$name])) {
                    continue;
                }
                $hms = (string) $map[$name];
                $mins = 0;
                if (preg_match('/^(\d{1,2}):(\d{2})/', $hms, $m)) {
                    $mins = ((int) $m[1]) * 60 + (int) $m[2];
                }
                $timings[] = [
                    'name' => $name,
                    'time' => headcount_kiosk_format_prayer_time($hms),
                    'minutes' => $mins,
                ];
            }
            if ($timings === []) {
                return $empty;
            }
            $cached = [
                'available' => true,
                'note' => null,
                'date' => $date,
                'timings' => $timings,
            ];
            @file_put_contents($cacheFile, json_encode($cached));
        }
        return $cached;
    }
}

