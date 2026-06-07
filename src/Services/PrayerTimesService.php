<?php

namespace Headcount\Services;

/**
 * Fetches prayer times from Aladhan API (https://aladhan.com/prayer-times-api).
 * Used to derive session/event start times as "X minutes after" a given salah.
 */
class PrayerTimesService
{
    private const BASE = 'https://api.aladhan.com/v1';

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
            $out[$name] = self::normalizeTimeString($timeStr);
        }
        return $out;
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
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300 && is_string($body)) {
                return $body;
            }
            return null;
        }
        $ctx = stream_context_create([
            'http' => ['timeout' => 12, 'header' => "Accept: application/json\r\n"],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : null;
    }
}
