<?php

/**
 * URL resolution helpers (app/portal bases — cron/CLI safe when config is set).
 */

/**
 * Whether a hostname is loopback / local dev.
 */
function headcount_is_local_hostname(string $host): bool
{
    $h = strtolower(trim($host));
    if ($h === '') {
        return false;
    }

    return str_contains($h, 'localhost')
        || str_contains($h, '127.0.0.1')
        || str_contains($h, '::1')
        || $h === '0.0.0.0';
}

/**
 * Best-effort public hostname for the current HTTP request (proxy-aware).
 */
function headcount_request_host(): string
{
    $forwardedHost = isset($_SERVER['HTTP_X_FORWARDED_HOST']) ? trim((string) $_SERVER['HTTP_X_FORWARDED_HOST']) : '';
    if ($forwardedHost !== '') {
        return trim(explode(',', $forwardedHost)[0]);
    }
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($host !== '') {
        return $host;
    }

    return trim((string) ($_SERVER['SERVER_NAME'] ?? ''));
}

/**
 * Best-effort request scheme (proxy-aware).
 */
function headcount_request_protocol(): string
{
    $forwardedProto = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? strtolower(trim((string) $_SERVER['HTTP_X_FORWARDED_PROTO'])) : '';
    if ($forwardedProto === 'https' || $forwardedProto === 'http') {
        return $forwardedProto;
    }
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https';
    }
    if (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return 'https';
    }

    return 'http';
}

/**
 * Read a Headcount URL override from environment (config/.env or server env).
 */
function headcount_env_url(string $key): string
{
    foreach ([getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null] as $value) {
        if ($value !== false && $value !== null && trim((string) $value) !== '') {
            return rtrim(trim((string) $value), '/');
        }
    }

    return '';
}

/**
 * Derive app base URL from the current request when config still points at localhost.
 */
function headcount_request_derived_app_base(?string $configuredUrl = null): ?string
{
    $requestHost = headcount_request_host();
    if ($requestHost === '' || headcount_is_local_hostname($requestHost)) {
        return null;
    }

    $hostNoPort = (string) preg_replace('#:\d+$#', '', $requestHost);
    $path = '';
    if ($configuredUrl !== null && trim($configuredUrl) !== '') {
        $parsedPath = parse_url($configuredUrl, PHP_URL_PATH);
        $path = ($parsedPath && $parsedPath !== '/') ? rtrim($parsedPath, '/') : '';
    }
    if ($path === '') {
        $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $basePath = dirname($scriptName);
        if (preg_match('#/portal$#', $basePath)) {
            $basePath = dirname(dirname($basePath));
        }
        $basePath = str_replace('/public', '', $basePath);
        $path = rtrim($basePath, '/');
    }

    return headcount_request_protocol() . '://' . rtrim($hostNoPort, '/') . $path;
}

/**
 * When config still points at localhost but the request is on a public host,
 * rebuild the URL using the request host and the path from the configured URL.
 */
function headcount_public_url_override(string $configuredUrl): ?string
{
    $configuredUrl = trim($configuredUrl);
    if ($configuredUrl === '') {
        return null;
    }
    $cfgHost = parse_url($configuredUrl, PHP_URL_HOST) ?? $configuredUrl;
    if (!headcount_is_local_hostname((string) $cfgHost)) {
        return null;
    }

    return headcount_request_derived_app_base($configuredUrl);
}

/**
 * Application base URL for admin links and other app.url-based emails.
 *
 * @param array $config Application config
 * @return string Absolute base URL without trailing slash
 */
function headcount_app_base_url(array $config): string
{
    $fromEnv = headcount_env_url('HEADCOUNT_APP_URL');
    if ($fromEnv !== '' && !headcount_is_local_hostname((string) (parse_url($fromEnv, PHP_URL_HOST) ?? $fromEnv))) {
        return $fromEnv;
    }

    $appUrl = isset($config['app']['url']) ? trim((string) $config['app']['url']) : '';
    $override = headcount_public_url_override($appUrl);
    if ($override !== null) {
        return $override;
    }

    $appHost = $appUrl !== '' ? (parse_url($appUrl, PHP_URL_HOST) ?? $appUrl) : '';
    if ($appUrl !== '' && !headcount_is_local_hostname((string) $appHost)) {
        return rtrim($appUrl, '/');
    }

    $derived = headcount_request_derived_app_base($appUrl);
    if ($derived !== null) {
        return $derived;
    }

    if ($fromEnv !== '') {
        return $fromEnv;
    }
    if ($appUrl !== '') {
        return rtrim($appUrl, '/');
    }

    return headcount_request_derived_app_base('') ?? 'http://localhost';
}

/**
 * Public site base URL for portal links (matches social share / email patterns).
 *
 * Order: app.portal_url → portal.public_base_url → app.url → request-derived base.
 * Use portal_url / public_base_url when the member site is on a different host than app.url
 * (e.g. app.url is admin, portal is https://events.example.org).
 *
 * @param array $config Application config
 * @return string Absolute base URL without trailing slash
 */
function headcount_portal_base_url(array $config): string
{
    $portalUrl = isset($config['app']['portal_url']) ? trim((string) $config['app']['portal_url']) : '';
    if ($portalUrl !== '' && !headcount_is_local_hostname((string) (parse_url($portalUrl, PHP_URL_HOST) ?? $portalUrl))) {
        return rtrim($portalUrl, '/');
    }
    $portalCfg = $config['portal'] ?? [];
    $publicBase = isset($portalCfg['public_base_url']) ? trim((string) $portalCfg['public_base_url']) : '';
    if ($publicBase !== '' && !headcount_is_local_hostname((string) (parse_url($publicBase, PHP_URL_HOST) ?? $publicBase))) {
        return rtrim($publicBase, '/');
    }
    $fromEnv = headcount_env_url('HEADCOUNT_PORTAL_URL');
    if ($fromEnv !== '' && !headcount_is_local_hostname((string) (parse_url($fromEnv, PHP_URL_HOST) ?? $fromEnv))) {
        return $fromEnv;
    }
    $fromEnvAlt = headcount_env_url('HEADCOUNT_PORTAL_PUBLIC_BASE_URL');
    if ($fromEnvAlt !== '' && !headcount_is_local_hostname((string) (parse_url($fromEnvAlt, PHP_URL_HOST) ?? $fromEnvAlt))) {
        return $fromEnvAlt;
    }

    return headcount_app_base_url($config);
}

/**
 * Canonical portal URL for an event detail page (member-facing).
 */
function headcount_event_portal_url(array $config, int $eventId): string
{
    return headcount_portal_base_url($config) . '/portal/event-details.php?id=' . $eventId;
}

/**
 * Signing key for feedback email links (HMAC).
 */
function headcount_event_feedback_signing_key(array $config): string
{
    return (string) ($config['security']['encryption_key'] ?? 'headcount-feedback');
}

/**
 * Verify signed feedback link token for a checked-in attendee.
 */
function headcount_event_feedback_verify_token(int $eventId, int $userId, string $token, array $config): bool
{
    if ($eventId <= 0 || $userId <= 0 || $token === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $eventId . '|' . $userId, headcount_event_feedback_signing_key($config));
    return hash_equals($expected, $token);
}

/**
 * Canonical portal URL for post-event feedback form.
 * When $userId is provided, appends a signed token so guests can submit without logging in.
 */
function headcount_event_feedback_portal_url(array $config, int $eventId, ?int $userId = null): string
{
    $url = headcount_portal_base_url($config) . '/portal/feedback.php?event_id=' . $eventId;
    if ($userId !== null && $userId > 0) {
        $token = hash_hmac('sha256', $eventId . '|' . $userId, headcount_event_feedback_signing_key($config));
        $url .= '&uid=' . $userId . '&token=' . rawurlencode($token) . '&from=email';
    }
    return $url;
}

/**
 * Canonical portal URL for a program detail page (member-facing).
 */
function headcount_program_portal_url(array $config, int $programId): string
{
    return headcount_portal_base_url($config) . '/portal/program-details.php?id=' . $programId;
}

/**
 * Public guest registration URL (no portal login required).
 */
function headcount_program_guest_register_url(array $config, int $programId): string
{
    return headcount_portal_base_url($config) . '/portal/guest-program-register.php?id=' . $programId;
}
