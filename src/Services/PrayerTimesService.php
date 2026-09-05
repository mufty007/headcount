<?php

namespace Headcount\Services;

/**
 * Fetches prayer times from Aladhan API (https://aladhan.com/prayer-times-api).
 * Used to derive session/event start times as "X minutes after" a given salah.
 *
 * Month calendars are cached in-process and fetched in parallel so generating
 * many session dates does not issue one HTTP request per day.
 */
class PrayerTimesService
{
    private const BASE = 'https://api.aladhan.com/v1';

    /** @var array<string, array<string, array<string, string>>> monthKey => Y-m-d => timings */
    private static $monthCache = [];

    /**
     * @return array<string, string>|null Map of prayer key => time string (e.g. "13:45")
     */
    public static function timingsByCity(string $dateYmd, string $city, string $country, int $method = 2): ?array
    {
        $city = trim($city);
        $country = trim($country);
        if ($city === '' || $country === '') {
            return null;
        }
        $dt = \DateTime::createFromFormat('Y-m-d', $dateYmd);
        if (!$dt) {
            return null;
        }
        $monthKey = self::monthCacheKey($dt, $city, $country, $method);
        if (!isset(self::$monthCache[$monthKey])) {
            self::prefetchMonths([[
                'year' => (int) $dt->format('Y'),
                'month' => (int) $dt->format('n'),
                'city' => $city,
                'country' => $country,
                'method' => $method,
            ]]);
        }
        $ymd = $dt->format('Y-m-d');
        if (isset(self::$monthCache[$monthKey][$ymd]) && is_array(self::$monthCache[$monthKey][$ymd])) {
            return self::$monthCache[$monthKey][$ymd];
        }
        // Month calendar already fetched (including failed/empty). Do not fall back
        // to one HTTP request per day — that is what made generation time out.
        if (array_key_exists($monthKey, self::$monthCache)) {
            return null;
        }
        return self::timingsByCitySingle($ymd, $city, $country, $method);
    }

    /**
     * Load month calendars for many dates in a few parallel HTTP calls.
     *
     * @param list<string> $datesYmd
     */
    public static function prefetchDates(array $datesYmd, string $city, string $country, int $method = 2): void
    {
        $city = trim($city);
        $country = trim($country);
        if ($city === '' || $country === '' || $datesYmd === []) {
            return;
        }
        $jobs = [];
        foreach ($datesYmd as $dateYmd) {
            $dt = \DateTime::createFromFormat('Y-m-d', (string) $dateYmd);
            if (!$dt) {
                continue;
            }
            $monthKey = self::monthCacheKey($dt, $city, $country, $method);
            if (isset(self::$monthCache[$monthKey]) || isset($jobs[$monthKey])) {
                continue;
            }
            $jobs[$monthKey] = [
                'year' => (int) $dt->format('Y'),
                'month' => (int) $dt->format('n'),
                'city' => $city,
                'country' => $country,
                'method' => $method,
            ];
        }
        if ($jobs !== []) {
            self::prefetchMonths(array_values($jobs));
        }
    }

    /**
     * @param string $prayerName One of: Fajr, Sunrise, Dhuhr, Asr, Maghrib, Isha (case-insensitive)
     */
    public static function timeAfterPrayer(string $dateYmd, string $city, string $country, string $prayerName, int $offsetMinutes, int $method = 2): ?string
    {
        $timings = self::timingsByCity($dateYmd, $city, $country, $method);
        if ($timings === null) {
            return null;
        }
        $key = self::normalizePrayerKey($prayerName);
        if ($key === null || !isset($timings[$key])) {
            return null;
        }
        $base = $timings[$key];
        $parts = explode(':', $base);
        if (count($parts) < 2) {
            return null;
        }
        $h = (int) $parts[0];
        $m = (int) $parts[1];
        $s = isset($parts[2]) ? (int) $parts[2] : 0;
        $total = $h * 3600 + $m * 60 + $s + $offsetMinutes * 60;
        $total = max(0, $total) % 86400;
        $h = intdiv($total, 3600);
        $m = intdiv($total % 3600, 60);
        $s = $total % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
    }

    /**
     * @param list<array{year:int,month:int,city:string,country:string,method:int}> $jobs
     */
    private static function prefetchMonths(array $jobs): void
    {
        if ($jobs === []) {
            return;
        }
        if (function_exists('curl_multi_init') && count($jobs) > 1) {
            self::prefetchMonthsCurlMulti($jobs);
            return;
        }
        foreach ($jobs as $job) {
            self::fetchAndStoreMonth($job);
        }
    }

    /**
     * @param list<array{year:int,month:int,city:string,country:string,method:int}> $jobs
     */
    private static function prefetchMonthsCurlMulti(array $jobs): void
    {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($jobs as $i => $job) {
            $url = self::calendarUrl($job);
            $ch = curl_init($url);
            curl_setopt_array($ch, self::curlOptions());
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }
        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        foreach ($handles as $i => $ch) {
            $body = curl_multi_getcontent($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
            $job = $jobs[$i];
            $monthKey = self::monthCacheKeyFromParts(
                (int) $job['year'],
                (int) $job['month'],
                (string) $job['city'],
                (string) $job['country'],
                (int) $job['method']
            );
            if ($code >= 200 && $code < 300 && is_string($body) && $body !== '') {
                $parsed = self::parseCalendarBody($body);
                self::$monthCache[$monthKey] = $parsed !== null ? $parsed : [];
            } elseif (!isset(self::$monthCache[$monthKey])) {
                self::$monthCache[$monthKey] = [];
            }
        }
        curl_multi_close($mh);
    }

    /**
     * @param array{year:int,month:int,city:string,country:string,method:int} $job
     */
    private static function fetchAndStoreMonth(array $job): void
    {
        $monthKey = self::monthCacheKeyFromParts(
            (int) $job['year'],
            (int) $job['month'],
            (string) $job['city'],
            (string) $job['country'],
            (int) $job['method']
        );
        $raw = self::httpGet(self::calendarUrl($job));
        $parsed = is_string($raw) ? self::parseCalendarBody($raw) : null;
        self::$monthCache[$monthKey] = $parsed !== null ? $parsed : [];
    }

    /**
     * @param array{year:int,month:int,city:string,country:string,method:int} $job
     */
    private static function calendarUrl(array $job): string
    {
        $query = http_build_query([
            'city' => $job['city'],
            'country' => $job['country'],
            'method' => $job['method'],
        ]);
        return self::BASE . '/calendarByCity/' . (int) $job['year'] . '/' . (int) $job['month'] . '?' . $query;
    }

    /**
     * @return array<string, array<string, string>>|null Y-m-d => timings
     */
    private static function parseCalendarBody(string $raw): ?array
    {
        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['data']) || !is_array($json['data'])) {
            return null;
        }
        $out = [];
        foreach ($json['data'] as $day) {
            if (!is_array($day) || empty($day['timings']) || !is_array($day['timings'])) {
                continue;
            }
            $ymd = null;
            $gDate = $day['date']['gregorian']['date'] ?? null;
            if (is_string($gDate) && preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $gDate, $m)) {
                $ymd = $m[3] . '-' . $m[2] . '-' . $m[1];
            }
            if ($ymd === null) {
                continue;
            }
            $timings = [];
            foreach ($day['timings'] as $name => $timeStr) {
                if (!is_string($timeStr)) {
                    continue;
                }
                $timings[(string) $name] = self::normalizeTimeString($timeStr);
            }
            if ($timings !== []) {
                $out[$ymd] = $timings;
            }
        }
        return $out;
    }

    /**
     * @return array<string, string>|null
     */
    private static function timingsByCitySingle(string $dateYmd, string $city, string $country, int $method): ?array
    {
        $dt = \DateTime::createFromFormat('Y-m-d', $dateYmd);
        if (!$dt) {
            return null;
        }
        $dmy = $dt->format('d-m-Y');
        $query = http_build_query([
            'city' => $city,
            'country' => $country,
            'method' => $method,
        ]);
        $url = self::BASE . '/timingsByCity/' . rawurlencode($dmy) . '?' . $query;
        $raw = self::httpGet($url);
        if ($raw === null) {
            return null;
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || empty($json['data']['timings']) || !is_array($json['data']['timings'])) {
            return null;
        }
        $out = [];
        foreach ($json['data']['timings'] as $name => $timeStr) {
            if (!is_string($timeStr)) {
                continue;
            }
            $out[(string) $name] = self::normalizeTimeString($timeStr);
        }
        $monthKey = self::monthCacheKey($dt, $city, $country, $method);
        if (!isset(self::$monthCache[$monthKey])) {
            self::$monthCache[$monthKey] = [];
        }
        self::$monthCache[$monthKey][$dateYmd] = $out;
        return $out;
    }

    private static function monthCacheKey(\DateTimeInterface $dt, string $city, string $country, int $method): string
    {
        return self::monthCacheKeyFromParts(
            (int) $dt->format('Y'),
            (int) $dt->format('n'),
            $city,
            $country,
            $method
        );
    }

    private static function monthCacheKeyFromParts(int $year, int $month, string $city, string $country, int $method): string
    {
        return strtolower($city) . '|' . strtolower($country) . '|' . $method . '|' . $year . '-' . $month;
    }

    /**
     * @return array<int, mixed>
     */
    private static function curlOptions(): array
    {
        return [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ];
    }

    private static function normalizePrayerKey(string $name): ?string
    {
        foreach (['Fajr', 'Sunrise', 'Dhuhr', 'Asr', 'Maghrib', 'Isha'] as $k) {
            if (strcasecmp(trim($name), $k) === 0) {
                return $k;
            }
        }
        return null;
    }

    private static function normalizeTimeString(string $s): string
    {
        $s = trim(preg_replace('/\s*\([^)]*\)\s*/', '', $s));
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $s, $m)) {
            $h = (int) $m[1];
            $min = (int) $m[2];
            $sec = isset($m[3]) ? (int) $m[3] : 0;
            return sprintf('%02d:%02d:%02d', $h, $min, $sec);
        }
        return $s;
    }

    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, self::curlOptions());
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300 && is_string($body)) {
                return $body;
            }
            return null;
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => 6, 'header' => "Accept: application/json\r\n"],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : null;
    }
}
