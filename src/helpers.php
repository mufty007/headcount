<?php

/**
 * Global Helper Functions
 * Procedural utility functions for use throughout the application
 */

/**
 * Escape HTML output
 * 
 * @param string $string The string to escape
 * @return string Escaped string
 */
if (!function_exists('e')) {
    function e($string) {
        return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Month-over-month percent change for dashboard trend badges.
 */
function headcount_percent_trend($current, $previous): ?float
{
    $current = (float) $current;
    $previous = (float) $previous;
    if ($previous == 0.0) {
        if ($current == 0.0) {
            return 0.0;
        }
        return 100.0;
    }
    return round((($current - $previous) / $previous) * 100, 1);
}

/**
 * Safe raw HTML inside a <textarea> (prevents breaking out of textarea/script).
 */
function headcount_wysiwyg_textarea_body($html): string {
    return str_ireplace(['</textarea', '</script'], ['&lt;/textarea', '&lt;/script'], (string) $html);
}

/**
 * Prepare facility description HTML for public display (Quill / encoded markup).
 *
 * @return array{has: bool, html: string}
 */
function headcount_facility_description_for_display(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') {
        return ['has' => false, 'html' => ''];
    }
    $decoded = headcount_undo_nested_html_entity_encoding($raw);
    $stripped = preg_replace('/<script\b[^>]*>.*?<\/script\s*>/is', '', $decoded);
    $decoded = is_string($stripped) ? $stripped : $decoded;
    if (!preg_match('/<[a-z][\s\S]*>/i', $decoded) && preg_match('/&lt;\s*[a-z]/i', $decoded)) {
        $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $textCheck = strip_tags($decoded);
    $textCheck = html_entity_decode($textCheck, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $textCheck = str_replace(["\xc2\xa0", '&nbsp;', "\u{00A0}"], ' ', $textCheck);
    $textCheck = trim(preg_replace('/\s+/u', ' ', $textCheck));
    if ($textCheck === '' && !preg_match('/<(img|video|iframe)\b/i', $decoded)) {
        return ['has' => false, 'html' => ''];
    }
    if (preg_match('/<[a-z][\s\S]*>/i', $decoded)) {
        return ['has' => true, 'html' => $decoded];
    }
    $parts = [];
    foreach (preg_split('/\r\n|\r|\n/', $decoded) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts[] = '<p>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $html = $parts ? implode('', $parts) : '<p>' . htmlspecialchars($decoded, ENT_QUOTES, 'UTF-8') . '</p>';
    return ['has' => true, 'html' => $html];
}

/**
 * Count words in plain text (tags stripped).
 */
function headcount_count_words(string $text): int {
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    if ($text === '') {
        return 0;
    }
    $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    return $words ? count($words) : 0;
}

/**
 * Validate facility booking purpose text (max words).
 */
function headcount_validate_booking_purpose(string $purpose, int $maxWords = 200): ?string {
    $purpose = trim(strip_tags($purpose));
    if ($purpose === '') {
        return 'Event / purpose is required.';
    }
    $count = headcount_count_words($purpose);
    if ($count > $maxWords) {
        return 'Event / purpose must be ' . $maxWords . ' words or fewer (currently ' . $count . ').';
    }
    return null;
}

/**
 * Resolve optional facility_id for an event (null = no link). Returns false if invalid id was requested.
 *
 * @param mixed $raw
 * @return int|null|false null when empty/unset, int when valid, false when invalid
 */
function headcount_resolve_event_facility_id(\Headcount\Helpers\Database $db, int $organizationId, $raw)
{
    if (!$db->hasColumn('events', 'facility_id')) {
        return null;
    }
    if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
        return null;
    }
    $id = (int) $raw;
    if ($id <= 0) {
        return false;
    }
    $row = $db->queryOne(
        'SELECT id FROM facilities WHERE id = :id AND organization_id = :org AND status = :st LIMIT 1',
        ['id' => $id, 'org' => $organizationId, 'st' => 'active']
    );
    return !empty($row) ? $id : false;
}

/**
 * Resolve optional facility_id from event admin form POST (null = no facility link).
 */
function headcount_event_facility_id_from_post(\Headcount\Helpers\Database $db, int $organizationId): ?int
{
    $resolved = headcount_resolve_event_facility_id($db, $organizationId, post('facility_id', ''));
    return $resolved === false ? null : $resolved;
}

/**
 * @return string|null Error message when facility is linked but times are missing.
 */
function headcount_validate_event_facility_times(?int $facilityId, string $startTime, string $endTime): ?string
{
    if ($facilityId === null || $facilityId <= 0) {
        return null;
    }
    if (trim($startTime) === '' || trim($endTime) === '') {
        return 'Start and end time are required when a facility is linked.';
    }
    return null;
}

/**
 * Validation errors for facility_id on event API create/update payloads.
 *
 * @param array<string, mixed> $input
 * @return list<string>
 */
function headcount_event_facility_api_errors(\Headcount\Helpers\Database $db, int $organizationId, array $input): array
{
    if (!$db->hasColumn('events', 'facility_id') || !array_key_exists('facility_id', $input)) {
        return [];
    }
    $errors = [];
    $resolved = headcount_resolve_event_facility_id($db, $organizationId, $input['facility_id']);
    if ($resolved === false) {
        $errors[] = 'Selected facility is not valid.';
        return $errors;
    }
    $timeErr = headcount_validate_event_facility_times(
        $resolved,
        (string) ($input['start_time'] ?? ''),
        (string) ($input['end_time'] ?? '')
    );
    if ($timeErr !== null) {
        $errors[] = $timeErr;
    }
    return $errors;
}

if (!function_exists('headcount_try_serve_admin_js_bundle')) {
/**
 * If the request targets /admin/js/*.js, stream the bundle and exit (no HTML bootstrap).
 */
function headcount_try_serve_admin_js_bundle(): bool
{
    static $allowed = [
        'event-wizard-steps.js',
        'event-pricing-tabs.js',
        'event-custom-questions.js',
    ];

    $uris = [];
    foreach (['REQUEST_URI', 'REDIRECT_URL'] as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $path = parse_url((string) $_SERVER[$key], PHP_URL_PATH);
        if (is_string($path) && $path !== '') {
            $uris[] = $path;
        }
    }
    if (!empty($_SERVER['PATH_INFO']) && is_string($_SERVER['PATH_INFO'])) {
        $uris[] = $_SERVER['PATH_INFO'];
    }

    $name = null;
    foreach ($uris as $requestPath) {
        if (preg_match('#/admin/js/([^/]+\.js)$#i', $requestPath, $m)) {
            $name = $m[1];
            break;
        }
    }
    if ($name === null || !in_array($name, $allowed, true)) {
        return false;
    }

    $root = dirname(__DIR__);
    $path = $root . '/public/admin/js/' . $name;
    if (!is_file($path)) {
        return false;
    }

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    if (!headers_sent()) {
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=2592000');
        header('X-Content-Type-Options: nosniff');
        $mtime = (int) @filemtime($path);
        if ($mtime > 0) {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
        }
    }
    readfile($path);
    exit;
}
}

if (!function_exists('headcount_admin_js_static_web_base')) {
/**
 * Web path to public/admin/js/ derived from DOCUMENT_ROOT (same approach as admin CSS).
 */
function headcount_admin_js_static_web_base(): ?string
{
    $root = dirname(__DIR__);
    $jsDirFs = realpath($root . '/public/admin/js');
    $docRootFs = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath((string) $_SERVER['DOCUMENT_ROOT']) : false;
    if ($jsDirFs === false || $docRootFs === false) {
        return null;
    }

    $jsNorm = str_replace('\\', '/', $jsDirFs);
    $rootNorm = str_replace('\\', '/', $docRootFs);
    if (!str_starts_with($jsNorm, $rootNorm)) {
        return null;
    }

    $rel = substr($jsNorm, strlen($rootNorm));
    $web = '/' . trim($rel, '/');
    return $web !== '/' ? $web : null;
}
}

if (!function_exists('headcount_admin_js_admin_router_base')) {
/**
 * Web path prefix for the admin router (…/admin), without index.php.
 */
function headcount_admin_js_admin_router_base(): string
{
    global $adminBase, $basePath;

    if (!empty($adminBase)) {
        return rtrim((string) $adminBase, '/');
    }

    if (!empty($basePath)) {
        $bp = rtrim((string) $basePath, '/');
        if ($bp === '' || $bp === '/') {
            return '/admin';
        }
        if (str_contains($bp, '/public')) {
            return $bp . '/admin';
        }
        return $bp . '/admin';
    }

    return '/admin';
}
}

if (!function_exists('headcount_admin_js_web_base')) {
/**
 * Web path prefix for admin JS bundles (real files under public/admin/js/).
 */
function headcount_admin_js_web_base(): string
{
    $static = headcount_admin_js_static_web_base();
    if ($static !== null && $static !== '') {
        return $static;
    }

    global $adminBase;
    if (!empty($adminBase)) {
        return rtrim((string) $adminBase, '/') . '/js';
    }

    return '/public/admin/js';
}
}

if (!function_exists('headcount_admin_js_url')) {
/**
 * URL for an admin JS bundle. Uses a filesystem-derived static path when possible;
 * otherwise falls back to the admin-js router (avoids HTML 404/login being parsed as JS).
 */
function headcount_admin_js_url(string $filename): string
{
    $filename = ltrim($filename, '/');
    $pathOnly = $filename;
    $extra = [];
    if (str_contains($filename, '?')) {
        [$pathOnly, $query] = explode('?', $filename, 2);
        parse_str($query, $extra);
    }
    $pathOnly = basename($pathOnly);

    $staticBase = headcount_admin_js_static_web_base();
    if ($staticBase !== null && $staticBase !== '') {
        $url = $staticBase . '/' . $pathOnly;
        if ($extra !== []) {
            $url .= '?' . http_build_query($extra);
        }
        return preg_replace('#/+#', '/', $url) ?? $url;
    }

    $router = headcount_admin_js_admin_router_base();
    $params = array_merge(['page' => 'admin-js', 'f' => $pathOnly], $extra);
    $url = $router . '/index.php?' . http_build_query($params);

    return preg_replace('#/+#', '/', $url) ?? $url;
}
}

if (!function_exists('headcount_admin_js_disk_paths')) {
/**
 * @return list<string> Absolute paths to an admin JS file on disk.
 */
function headcount_admin_js_disk_paths(string $baseFile): array
{
    $baseFile = basename(preg_replace('/\?.*$/', '', ltrim($baseFile, '/')));
    $root = dirname(__DIR__);
    return [
        $root . '/public/admin/js/' . $baseFile,
    ];
}
}

/**
 * Escape "</script" for safe inline &lt;script&gt; embedding without breaking JS "&lt;" operators.
 */
function headcount_admin_js_inline_sanitize(string $js): string
{
    return preg_replace('#</script\b#i', '<\\/script', $js) ?? $js;
}

/**
 * JSON safe to embed in a &lt;script&gt; block (never returns empty on encode failure).
 */
function headcount_json_for_script($value): string
{
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }
    $json = json_encode($value, $flags);
    return $json === false ? 'null' : $json;
}

/**
 * Output a &lt;script src&gt; tag (always external — avoids &lt;/script&gt; and "&lt;" in HTML breaking inline JS).
 */
function headcount_admin_js_emit(string $filename, bool $defer = false): void
{
    $deferAttr = $defer ? ' defer' : '';
    echo '<script src="' . e(headcount_admin_js_url($filename)) . '"' . $deferAttr . '></script>' . "\n";
}

/**
 * Undo double- or triple-encoded HTML entities (e.g. "&amp;amp;" → "&").
 */
function headcount_undo_nested_html_entity_encoding(string $s): string {
    $out = $s;
    for ($i = 0; $i < 12; $i++) {
        $next = html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($next === $out) {
            break;
        }
        $out = $next;
    }
    return trim($out);
}

/**
 * Flatten over-escaped ampersands in plain-text fields (title, location) for display.
 */
function headcount_flatten_ampersand_in_plain_text(string $s): string {
    $out = headcount_undo_nested_html_entity_encoding($s);
    while (str_contains($out, '&amp;')) {
        $next = html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($next === $out) {
            $out = str_replace('&amp;', '&', $out);
            break;
        }
        $out = $next;
    }
    return trim($out);
}

/**
 * @param array<string, mixed> $event
 */
function headcount_normalize_event_text_fields(array &$event): void {
    foreach (['title', 'location'] as $k) {
        if (!isset($event[$k]) || !is_string($event[$k]) || $event[$k] === '') {
            continue;
        }
        $event[$k] = headcount_flatten_ampersand_in_plain_text($event[$k]);
    }
    if (isset($event['description']) && is_string($event['description']) && $event['description'] !== '') {
        $event['description'] = headcount_undo_nested_html_entity_encoding($event['description']);
    }
}

/**
 * Head-count stats for check-in from DB rows shaped like checkin-rsvps (rsvp_status, guest_count, checked_in).
 *
 * @param list<array<string, mixed>> $rows
 * @return array{total_heads: int, not_checked_in_heads: int, total_registrants_yes: int}
 */
function headcount_checkin_head_stats_from_rsvp_rows(array $rows): array {
    $totalHeads = 0;
    $pendingHeads = 0;
    $yesRows = 0;
    foreach ($rows as $r) {
        $st = strtolower(trim((string) ($r['rsvp_status'] ?? '')));
        if ($st !== 'yes') {
            continue;
        }
        $yesRows++;
        $gc = (int) ($r['guest_count'] ?? 0);
        $heads = 1 + $gc;
        $totalHeads += $heads;
        if ((int) ($r['checked_in'] ?? 0) === 0) {
            $pendingHeads += $heads;
        }
    }
    return [
        'total_heads' => $totalHeads,
        'not_checked_in_heads' => $pendingHeads,
        'total_registrants_yes' => $yesRows,
    ];
}

/**
 * Canonical RSVP "yes" head count and registrant row count from database (matches admin event cards).
 *
 * @param \Headcount\Helpers\Database $db
 * @return array{heads: int, registrants: int}
 */
function headcount_rsvp_yes_canonical_counts(\Headcount\Helpers\Database $db, int $eventId): array
{
    try {
        $cols = $db->query('SHOW COLUMNS FROM rsvps');
        $names = array_column($cols, 'Field');
        $hasGuest = in_array('guest_count', $names, true);
        $headsExpr = $hasGuest
            ? 'COALESCE(SUM(1 + COALESCE(guest_count, 0)), 0)'
            : 'COALESCE(COUNT(*), 0)';
        $row = $db->queryOne(
            "SELECT {$headsExpr} AS heads, COUNT(*) AS registrants FROM rsvps WHERE event_id = :eid AND status = 'yes'",
            ['eid' => $eventId]
        );

        return [
            'heads' => (int) ($row['heads'] ?? 0),
            'registrants' => (int) ($row['registrants'] ?? 0),
        ];
    } catch (\Throwable $e) {
        return ['heads' => 0, 'registrants' => 0];
    }
}

/**
 * Merge row-derived check-in head stats with SQL-canonical totals so guest totals match the events list.
 *
 * @param array{total_heads: int, not_checked_in_heads: int, total_registrants_yes: int} $headStats
 * @param array{heads: int, registrants: int} $canonical
 * @return array{total_heads: int, not_checked_in_heads: int, total_registrants_yes: int}
 */
function headcount_merge_canonical_rsvp_yes_headcounts(array $headStats, array $canonical): array
{
    $canH = max(0, (int) ($canonical['heads'] ?? 0));
    $canR = max(0, (int) ($canonical['registrants'] ?? 0));
    if ($canH === 0 && $canR === 0) {
        return $headStats;
    }
    $rowTotal = max(0, (int) ($headStats['total_heads'] ?? 0));
    $rowPending = max(0, (int) ($headStats['not_checked_in_heads'] ?? 0));
    $checkedHeads = max(0, $rowTotal - $rowPending);

    return [
        'total_heads' => $canH,
        'not_checked_in_heads' => max(0, $canH - $checkedHeads),
        'total_registrants_yes' => $canR > 0 ? $canR : (int) ($headStats['total_registrants_yes'] ?? 0),
    ];
}

/**
 * Decode HTML entities on event title/location/description (mutates row in place).
 * Implemented here (not delegated to Utilities) so admin pages never fatal if an
 * older Utilities.php or opcache is out of sync with this file.
 *
 * @param array<string, mixed> $event
 */
function headcount_decode_html_entities_in_event_row(array &$event): void {
    foreach (['title', 'location'] as $k) {
        if (isset($event[$k]) && $event[$k] !== null && $event[$k] !== '') {
            $event[$k] = trim(html_entity_decode((string) $event[$k], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    }
    if (isset($event['description']) && $event['description'] !== null && $event['description'] !== '') {
        $event['description'] = trim(html_entity_decode((string) $event['description'], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    headcount_normalize_event_text_fields($event);
}

/**
 * @param list<array<string, mixed>> $rows
 */
function headcount_decode_html_entities_in_event_rows(array &$rows): void {
    foreach ($rows as &$row) {
        if (is_array($row)) {
            headcount_decode_html_entities_in_event_row($row);
        }
    }
    unset($row);
}

/**
 * Read event visibility from POST (radio group), normalize, and tolerate duplicate field names.
 */
function headcount_post_visibility(string $key = 'visibility', string $default = 'public'): string {
    if (!isset($_POST[$key])) {
        return \Headcount\Services\EventVisibilityService::normalize($default);
    }
    $raw = $_POST[$key];
    if (is_array($raw)) {
        $raw = (string) end($raw);
    }

    return \Headcount\Services\EventVisibilityService::normalize((string) $raw);
}

/**
 * MariaDB-safe column check. Tolerates legacy Database::hasColumn() that used SHOW … LIKE ?.
 */
function headcount_db_has_column(\Headcount\Helpers\Database $db, string $table, string $column): bool
{
    static $cache = [];

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) {
        return false;
    }

    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $cache[$key] = $db->hasColumn($table, $column);

        return $cache[$key];
    } catch (\Throwable $e) {
        error_log("headcount_db_has_column({$table}.{$column}): " . $e->getMessage());
    }

    try {
        $pdo = $db->getConnection();
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';
        $sql = "SHOW COLUMNS FROM {$quotedTable} LIKE " . $pdo->quote($column);
        $stmt = $pdo->query($sql);
        $cache[$key] = $stmt !== false && $stmt->fetch() !== false;
    } catch (\Throwable $e) {
        error_log("headcount_db_has_column fallback({$table}.{$column}): " . $e->getMessage());
        $cache[$key] = false;
    }

    return $cache[$key];
}

/**
 * MariaDB-safe table check. Works even when Database::tableExists() is missing on the server.
 */
function headcount_db_table_exists(\Headcount\Helpers\Database $db, string $table): bool
{
    static $cache = [];

    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }

    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        if (method_exists($db, 'tableExists')) {
            $cache[$table] = $db->tableExists($table);

            return $cache[$table];
        }
    } catch (\Throwable $e) {
        error_log("headcount_db_table_exists({$table}): " . $e->getMessage());
    }

    try {
        $pdo = $db->getConnection();
        $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
        $cache[$table] = $stmt !== false && $stmt->fetch() !== false;
    } catch (\Throwable $e) {
        error_log("headcount_db_table_exists fallback({$table}): " . $e->getMessage());
        $cache[$table] = false;
    }

    return $cache[$table];
}

/**
 * Whether events.visibility exists (SHOW COLUMNS can mis-detect on some hosts; probe SELECT as fallback).
 */
function headcount_events_has_visibility_column(\Headcount\Helpers\Database $db): bool {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    if (headcount_db_has_column($db, 'events', 'visibility')) {
        $cached = true;

        return true;
    }
    try {
        $db->queryOne('SELECT `visibility` FROM `events` LIMIT 1');
        $cached = true;
    } catch (\Throwable $e) {
        $cached = false;
    }

    return $cached;
}

/**
 * Default organization for portal API when guest has no ?organization_id= (single-tenant or config).
 *
 * @param array<string,mixed> $config
 */
function headcount_resolve_portal_organization_id(?int $authenticatedOrgId, array $config, \Headcount\Helpers\Database $db): ?int {
    if ($authenticatedOrgId !== null && $authenticatedOrgId > 0) {
        return $authenticatedOrgId;
    }
    if (!empty($_GET['organization_id']) && ctype_digit((string) $_GET['organization_id'])) {
        $fromQuery = (int) $_GET['organization_id'];
        if ($fromQuery > 0) {
            return $fromQuery;
        }
    }
    $portalCfg = $config['portal'] ?? [];
    $fromCfg = isset($portalCfg['organization_id']) ? (int) $portalCfg['organization_id'] : 0;
    if ($fromCfg > 0) {
        return $fromCfg;
    }
    try {
        $rows = $db->query('SELECT `id` FROM `organizations` ORDER BY `id` ASC LIMIT 2');
        if (is_array($rows) && count($rows) === 1) {
            $id = (int) ($rows[0]['id'] ?? 0);

            return $id > 0 ? $id : null;
        }
    } catch (\Throwable $e) {
    }

    return null;
}

/**
 * Published event ids in a recurring series (root row + instances).
 * Uses positional placeholders — safe when PDO native prepares disallow reused :names.
 *
 * @return int[]
 */
function headcount_published_series_event_ids(\Headcount\Helpers\Database $db, int $rootId): array
{
    if ($rootId <= 0) {
        return [];
    }
    try {
        $rows = $db->query(
            "SELECT id FROM events
             WHERE status = 'published' AND (id = ? OR parent_event_id = ?)
             ORDER BY event_date ASC, COALESCE(start_time, '00:00:00') ASC, id ASC",
            [$rootId, $rootId]
        );

        return array_map('intval', array_column($rows, 'id'));
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Redirect to a URL
 * 
 * @param string $url The URL to redirect to
 * @return void
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Set flash message in session
 * 
 * @param string $type Message type (e.g., 'success', 'error', 'warning', 'info')
 * @param string $message The message to display
 * @return void
 */
function setFlash($type, $message) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Get and clear flash message from session
 * 
 * @return array|null Flash message array with 'type' and 'message' keys, or null if none exists
 */
function getFlash() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Format date for display
 * 
 * @param string $date The date string to format
 * @param string $format Date format (default: 'M j, Y')
 * @return string Formatted date string
 */
function formatDate($date, $format = 'M j, Y') {
    if (empty($date)) {
        return '';
    }
    try {
        return date($format, strtotime($date));
    } catch (\Exception $e) {
        return $date;
    }
}

/**
 * Format time for display
 * 
 * @param string $time The time string to format
 * @param string $format Time format (default: 'g:i A')
 * @return string Formatted time string
 */
function formatTime($time, $format = 'g:i A') {
    if (empty($time)) {
        return '';
    }
    try {
        return date($format, strtotime($time));
    } catch (\Exception $e) {
        return $time;
    }
}

/**
 * Checkbox / boolean from JSON or multipart form.
 * FormData sends booleans as "1"/"0"; the string "false" is truthy for empty() — use this instead.
 * Only explicit truthy strings/numbers count as true (matches FILTER_VALIDATE_BOOLEAN truth set).
 *
 * @param array<string,mixed> $input
 */
function requestBoolFromInput(array $input, string $key, bool $default = false): bool {
    if (!array_key_exists($key, $input)) {
        return $default;
    }
    $v = $input[$key];
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v) || is_float($v)) {
        return (int) $v === 1;
    }
    if (is_string($v)) {
        $s = strtolower(trim($v));

        return in_array($s, ['1', 'true', 'yes', 'on'], true);
    }

    return false;
}

/**
 * True when the request body is multipart/form-data (use $_POST), not only when $_FILES is non-empty.
 */
function isMultipartFormRequest(): bool {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';

    return stripos($ct, 'multipart/form-data') !== false;
}

/**
 * True if recurrence_days / recurrence_days[] is present and non-empty (0 = Sunday is valid; do not use empty()).
 *
 * @param array<string,mixed> $input
 */
function recurrenceDaysProvided(array $input): bool {
    if (!array_key_exists('recurrence_days', $input)) {
        return false;
    }
    $rd = $input['recurrence_days'];
    if (is_array($rd)) {
        return count($rd) > 0;
    }

    return trim((string) $rd) !== '';
}

/**
 * Format a stored attendance datetime for display in the organization's timezone.
 * Interprets the DB timestamp in UTC (MySQL session time_zone), then converts to the org zone.
 */
function formatAttendanceLocalTimeForOrganization(?string $datetime, string $orgTimezone): string {
    if ($datetime === null || $datetime === '') {
        return '';
    }
    try {
        $storedTz = new \DateTimeZone('UTC');
        $dt = new \DateTimeImmutable($datetime, $storedTz);
        $dt = $dt->setTimezone(new \DateTimeZone(\Headcount\Helpers\OrgTimeZone::resolve($orgTimezone)));
        return $dt->format('g:i A');
    } catch (\Throwable $e) {
        return date('g:i A', strtotime($datetime));
    }
}

/**
 * Send JSON response
 * 
 * @param mixed $data The data to encode as JSON
 * @param int $status HTTP status code (default: 200)
 * @return void
 */
function jsonResponse($data, $status = 200) {
    // Clear any output buffers
    while (ob_get_level() > 0) {
        $output = ob_get_clean();
        if (!empty($output) && $output !== false) {
            error_log("jsonResponse - WARNING: Output buffer contained: " . substr($output, 0, 200));
        }
    }
    
    // Ensure headers haven't been sent
    if (headers_sent($file, $line)) {
        error_log("jsonResponse - WARNING: Headers already sent in $file on line $line");
    }
    
    http_response_code($status);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }

    $flags = JSON_UNESCAPED_SLASHES;
    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    $json = json_encode($data, $flags);
    if ($json === false) {
        error_log("jsonResponse - JSON encoding failed: " . json_last_error_msg());
        $json = json_encode(['success' => false, 'message' => 'Error encoding response'], $flags);
        if ($json === false) {
            $json = '{"success":false,"message":"Error encoding response"}';
        }
    }
    
    echo $json;
    exit;
}

/**
 * Web path to the public/ directory (no trailing slash), derived from the current request.
 * Examples: /Headcount/public, /public, or '' when the docroot is already public/.
 */
function headcount_public_web_root_path(): string
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/public/index.php');

    if (preg_match('#^(.*?)/public/#', $scriptName, $m)) {
        return rtrim($m[1], '/') . '/public';
    }

    if (preg_match('#^(.*?)/portal/#', $scriptName, $m)) {
        $prefix = rtrim($m[1], '/');
        return $prefix === '' ? '' : $prefix . '/public';
    }

    if (preg_match('#^(.*?)/admin/#', $scriptName, $m)) {
        $prefix = rtrim($m[1], '/');
        return $prefix === '' ? '/public' : $prefix . '/public';
    }

    if (preg_match('#^(.*?)/api/#', $scriptName, $m)) {
        return rtrim($m[1], '/');
    }

    // Root front controller (e.g. /Headcount/index.php includes public/index.php)
    $dir = rtrim(dirname($scriptName), '/');
    if ($dir !== '' && $dir !== '/') {
        return $dir . '/public';
    }

    return '/public';
}

/**
 * Public URL for a file under the uploads root, served via public/api/image.php.
 * Same pattern as the member portal (e.g. …/api/image.php?path=event-banners%2Ffile.png).
 *
 * @param string $relativePath Path relative to uploads dir, e.g. event-banners/foo.png
 */
function hc_public_api_image_url(string $relativePath): string
{
    $relativePath = trim(str_replace('\\', '/', $relativePath));
    if ($relativePath === '') {
        return '';
    }
    if (filter_var($relativePath, FILTER_VALIDATE_URL)) {
        return $relativePath;
    }
    if (preg_match('#/api/image\.php\?path=([^&]+)#', $relativePath, $m)) {
        $relativePath = rawurldecode($m[1]);
    }
    $relativePath = ltrim($relativePath, '/');
    if (strpos($relativePath, 'uploads/') === 0) {
        $relativePath = substr($relativePath, strlen('uploads/'));
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
    $webRoot = headcount_public_web_root_path();
    $imageScript = ($webRoot !== '' ? $webRoot : '') . '/api/image.php';

    return $protocol . '://' . $host . $imageScript . '?path=' . rawurlencode($relativePath);
}

/**
 * Validate email address
 * 
 * @param string $email The email address to validate
 * @return bool True if valid, false otherwise
 */
function isValidEmail($email) {
    return \Headcount\Helpers\Validator::email($email);
}

/**
 * Sanitize input string
 * 
 * @param string $input The input to sanitize
 * @return string Sanitized string
 */
function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Check if current request is POST
 * 
 * @return bool True if POST request, false otherwise
 */
function isPost() {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Get POST data by key
 * 
 * @param string $key The key to retrieve
 * @param mixed $default Default value if key doesn't exist
 * @return mixed The POST value or default
 */
function post($key, $default = null) {
    return $_POST[$key] ?? $default;
}

/**
 * Get GET data by key
 * 
 * @param string $key The key to retrieve
 * @param mixed $default Default value if key doesn't exist
 * @return mixed The GET value or default
 */
function get($key, $default = null) {
    return $_GET[$key] ?? $default;
}

/**
 * Format date and time for display
 * 
 * @param string $datetime The datetime string to format
 * @param string $format DateTime format (default: 'M j, Y g:i A')
 * @return string Formatted datetime string
 */
function formatDateTime($datetime, $format = 'M j, Y g:i A') {
    if (empty($datetime)) {
        return '';
    }
    try {
        return date($format, strtotime($datetime));
    } catch (\Exception $e) {
        return $datetime;
    }
}

/**
 * Get human-readable time ago string
 * 
 * @param string $datetime The datetime string
 * @return string Human-readable time ago string (e.g., "2 hours ago")
 */
function timeAgo($datetime) {
    if (empty($datetime)) {
        return '';
    }
    
    try {
        $timestamp = strtotime($datetime);
        $diff = time() - $timestamp;
        
        if ($diff < 60) {
            return 'just now';
        } elseif ($diff < 3600) {
            $minutes = floor($diff / 60);
            return $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 86400) {
            $hours = floor($diff / 3600);
            return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 604800) {
            $days = floor($diff / 86400);
            return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 2592000) {
            $weeks = floor($diff / 604800);
            return $weeks . ' week' . ($weeks > 1 ? 's' : '') . ' ago';
        } elseif ($diff < 31536000) {
            $months = floor($diff / 2592000);
            return $months . ' month' . ($months > 1 ? 's' : '') . ' ago';
        } else {
            $years = floor($diff / 31536000);
            return $years . ' year' . ($years > 1 ? 's' : '') . ' ago';
        }
    } catch (\Exception $e) {
        return '';
    }
}

/**
 * Wrap email HTML body with organization branding (logo header).
 * Use when sending emails so the logo appears in the header.
 *
 * @param string $htmlBody Inner HTML body
 * @param string|null $logoUrl Full URL to logo image (e.g. from org logo_path + app url)
 * @param string $orgName Organization name for header
 * @return string Wrapped HTML
 */
function wrapEmailWithBranding($htmlBody, $logoUrl = null, $orgName = '') {
    // Build the branding header block
    $brandHeader = '';
    if ($logoUrl !== null || $orgName !== '') {
        $brandHeader = '<div style="padding:16px 24px;border-bottom:1px solid #eee;background:#fafafa;text-align:center;">';
        if ($logoUrl !== null && $logoUrl !== '') {
            $brandHeader .= '<img src="' . htmlspecialchars($logoUrl) . '" alt="Logo" style="max-height:60px;max-width:200px;display:inline-block;margin-bottom:8px;">';
        }
        if ($orgName !== '') {
            $brandHeader .= '<div style="font-size:16px;font-weight:bold;color:#1e293b;">' . htmlspecialchars($orgName) . '</div>';
        }
        $brandHeader .= '</div>';
    }

    // If the body is already a full HTML document (e.g. Unlayer export),
    // inject the branding header into the existing <body> instead of double-wrapping.
    if (stripos(trim($htmlBody), '<!DOCTYPE') === 0 || stripos(trim($htmlBody), '<html') === 0) {
        if ($brandHeader !== '') {
            // Insert branding immediately after the opening <body> tag
            $htmlBody = preg_replace('/(<body[^>]*>)/i', '$1' . $brandHeader, $htmlBody, 1);
        }
        return $htmlBody;
    }

    // Plain HTML fragment — wrap with a container as before
    return '<div style="font-family:sans-serif;max-width:600px;margin:0 auto;border:1px solid #f1f5f9;">'
        . $brandHeader
        . '<div style="padding:32px;">' . $htmlBody . '</div>'
        . '</div>';
}


/**
 * Generate signed unsubscribe URL for campaign emails (CAN-SPAM).
 *
 * @param int $organizationId
 * @param string $email Recipient email
 * @param int|null $campaignId Optional campaign id
 * @param string $baseUrl Base URL of the app (e.g. config app.url)
 * @param string $signingKey Secret key for HMAC (e.g. config security.encryption_key)
 * @return string Full unsubscribe URL
 */
function generateUnsubscribeUrl($organizationId, $email, $campaignId, $baseUrl, $signingKey)
{
    $cid = $campaignId !== null && $campaignId !== '' ? (int) $campaignId : '';
    $payload = (int) $organizationId . '|' . $email . '|' . $cid;
    $token = hash_hmac('sha256', $payload, $signingKey ?: 'headcount-unsubscribe');
    $baseUrl = rtrim($baseUrl, '/');
    $path = (strpos($baseUrl, '/public') !== false ? $baseUrl : $baseUrl . '/public') . '/unsubscribe.php';
    return $path . '?org=' . (int) $organizationId . '&email=' . rawurlencode($email) . '&token=' . $token;
}

/**
 * Verify unsubscribe token (from GET params).
 *
 * @param int $organizationId
 * @param string $email
 * @param string $token
 * @param string $signingKey
 * @return bool
 */
function verifyUnsubscribeToken($organizationId, $email, $token, $signingKey)
{
    $expected = hash_hmac('sha256', (int) $organizationId . '|' . $email . '|', $signingKey ?: 'headcount-unsubscribe');
    $expected2 = hash_hmac('sha256', (int) $organizationId . '|' . $email . '|0', $signingKey ?: 'headcount-unsubscribe');
    return hash_equals($expected, $token) || hash_equals($expected2, $token);
}

/**
 * Append CAN-SPAM unsubscribe footer to email HTML.
 *
 * @param string $html Email body HTML
 * @param string $unsubscribeUrl Full URL for unsubscribe link
 * @param string $orgName Optional organization name for footer
 * @return string HTML with footer appended
 */
function appendUnsubscribeFooter($html, $unsubscribeUrl, $orgName = '')
{
    $footer = '<div style="margin-top:32px;padding-top:16px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;text-align:center;">';
    $footer .= '<a href="' . htmlspecialchars($unsubscribeUrl) . '" style="color:#6366f1;">Unsubscribe</a> from these emails';
    if ($orgName !== '') {
        $footer .= ' &middot; ' . htmlspecialchars($orgName);
    }
    $footer .= '</div>';

    // For full HTML documents (e.g. Unlayer), insert before </body> not after </html>
    if (stripos(trim($html), '<!DOCTYPE') === 0 || stripos(trim($html), '<html') === 0) {
        if (stripos($html, '</body>') !== false) {
            return preg_replace('/<\/body>/i', $footer . '</body>', $html, 1);
        }
        // No </body> tag found — just append inside the html
        return $html . $footer;
    }

    // Plain fragment — just append
    return $html . $footer;
}

/**
 * Build a fully-qualified logo URL for emails from an app base URL and stored logo path.
 *
 * @param string $appUrl   Base app URL from config, e.g. https://example.org/headcount
 * @param string $logoPath Stored logo path from organizations.logo_path (relative or absolute)
 * @return string|null Absolute logo URL or null if not available
 */
function buildLogoUrlForEmail($appUrl, $logoPath)
{
    $appUrl = rtrim((string)$appUrl, '/');
    if ($logoPath === null || $logoPath === '') {
        return null;
    }

    // Absolute URL already
    if (strpos($logoPath, 'http://') === 0 || strpos($logoPath, 'https://') === 0) {
        return $logoPath;
    }

    $normalizedPath = '/' . ltrim($logoPath, '/');

    // If it already starts with /public/, just prepend app url
    if (strpos($normalizedPath, '/public/') === 0) {
        return $appUrl . $normalizedPath;
    }

    // Default: assume stored relative to /public
    return $appUrl . '/public' . $normalizedPath;
}

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
 * Canonical portal URL for a program detail page (member-facing).
 */
function headcount_program_portal_url(array $config, int $programId): string
{
    return headcount_portal_base_url($config) . '/portal/program-details.php?id=' . $programId;
}

/**
 * Extract non-empty option labels from a custom question options array.
 *
 * @param array<int, mixed> $options
 * @return array<int, string>
 */
function headcount_event_question_option_labels(array $options): array
{
    $labels = [];
    foreach ($options as $opt) {
        $label = isset($opt['option_label']) ? trim((string) $opt['option_label']) : (is_string($opt) ? trim($opt) : '');
        if ($label !== '') {
            $labels[] = $label;
        }
    }
    return $labels;
}

/**
 * Normalize question type: checkbox with options becomes multi_checkbox.
 */
function headcount_normalize_event_question_type(string $qType, array $options): string
{
    if ($qType === 'checkbox' && headcount_event_question_option_labels($options) !== []) {
        return 'multi_checkbox';
    }
    return $qType;
}

/**
 * Default liability waiver body for RSVPs and program registration.
 */
function headcount_default_rsvp_waiver_text(): string
{
    return "LIABILITY WAIVER AND RELEASE\n\n"
        . "By registering for or attending this event or program, you acknowledge and agree to the following:\n\n"
        . "1. Assumption of Risk. You voluntarily assume all risks associated with participation, including injury, illness, property damage, or other loss, whether caused by negligence or otherwise.\n\n"
        . "2. Release of Liability. To the fullest extent permitted by law, you release and hold harmless the organization, its staff, volunteers, presenters, and affiliates from any and all claims, damages, or expenses arising from your participation.\n\n"
        . "3. Personal Responsibility. You accept full responsibility for your own actions and for the supervision of any minors or guests you bring.\n\n"
        . "4. Photo and Video. You understand that photographs and video may be taken at public events, in public places, or at our facilities. You grant the organization the right to use, share, publish, or distribute such images or recordings on social media, websites, newsletters, or other channels as the organization sees fit, without compensation.\n\n"
        . "5. Medical. You confirm that you are physically able to participate and will seek medical care independently if needed.\n\n"
        . "If you do not agree to these terms, do not register or attend.";
}

/**
 * Waiver settings for an organization (with safe defaults if columns missing).
 *
 * @return array{enabled: bool, checkbox_label: string, full_text: string}
 */
function headcount_org_waiver_settings(?array $org): array
{
    $defaultLabel = 'I agree to the liability waiver and release';
    $defaultText = headcount_default_rsvp_waiver_text();
    if (!is_array($org)) {
        return ['enabled' => true, 'checkbox_label' => $defaultLabel, 'full_text' => $defaultText];
    }
    $enabled = !array_key_exists('rsvp_waiver_enabled', $org) || (int) ($org['rsvp_waiver_enabled'] ?? 1) === 1;
    $label = trim((string) ($org['rsvp_waiver_checkbox_label'] ?? ''));
    $full = trim((string) ($org['rsvp_waiver_full_text'] ?? ''));
    return [
        'enabled' => $enabled,
        'checkbox_label' => $label !== '' ? $label : $defaultLabel,
        'full_text' => $full !== '' ? $full : $defaultText,
    ];
}

/**
 * Validate waiver acceptance from request input; returns error message or null if OK.
 *
 * @param array<string, mixed> $input
 */
function headcount_waiver_validation_error(?array $org, array $input): ?string
{
    $waiver = headcount_org_waiver_settings($org);
    if (!$waiver['enabled']) {
        return null;
    }
    $accepted = !empty($input['waiver_accepted']) || !empty($input['waiverAccepted']);
    if (!$accepted) {
        return 'You must accept the liability waiver to continue.';
    }
    return null;
}

/**
 * Record waiver acceptance timestamp on rsvps or program_registrations.
 */
function headcount_mark_waiver_accepted(\Headcount\Helpers\Database $db, string $table, int $recordId): void
{
    if (!in_array($table, ['rsvps', 'program_registrations'], true) || $recordId <= 0) {
        return;
    }
    try {
        if ($db->hasColumn($table, 'waiver_accepted_at')) {
            $db->execute("UPDATE {$table} SET waiver_accepted_at = NOW() WHERE id = ?", [$recordId]);
        }
    } catch (\Throwable $e) {
        error_log('headcount_mark_waiver_accepted failed: ' . $e->getMessage());
    }
}

/**
 * Attach waiver settings for portal forms (events/programs).
 *
 * @return array{enabled: bool, checkbox_label: string, full_text: string}
 */
function headcount_portal_waiver_payload(?array $org): array
{
    return headcount_org_waiver_settings($org);
}
