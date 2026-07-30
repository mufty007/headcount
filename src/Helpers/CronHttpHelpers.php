<?php

/**
 * HTTP/CLI cron access helpers.
 */

/**
 * Shared secret for HTTP-triggered cron URLs (Hostinger, wget, etc.).
 * Prefers config cron.http_secret, then cron.stripe_reconcile_secret, then HEADCOUNT_CRON_SECRET env.
 */
function headcount_cron_http_secret(array $config): string
{
    $fromConfig = trim((string) ($config['cron']['http_secret'] ?? ''));
    if ($fromConfig !== '') {
        return $fromConfig;
    }
    $legacy = trim((string) ($config['cron']['stripe_reconcile_secret'] ?? ''));
    if ($legacy !== '') {
        return $legacy;
    }
    $fromEnv = getenv('HEADCOUNT_CRON_SECRET');

    return is_string($fromEnv) ? trim($fromEnv) : '';
}

/**
 * Verify HTTP cron access (?key=, X-Cron-Secret, or X-Cron-Key). CLI calls skip auth.
 */
function headcount_cron_verify_http_access(array $config): void
{
    if (php_sapi_name() === 'cli') {
        return;
    }
    $secret = headcount_cron_http_secret($config);
    if ($secret === '') {
        jsonResponse([
            'success' => false,
            'message' => 'HTTP cron disabled: set cron.http_secret in config/config.php',
        ], 503);
        exit;
    }
    $provided = trim((string) (
        $_GET['key']
        ?? $_SERVER['HTTP_X_CRON_SECRET']
        ?? $_SERVER['HTTP_X_CRON_KEY']
        ?? ''
    ));
    if ($provided === '' || !hash_equals($secret, $provided)) {
        jsonResponse(['success' => false, 'message' => 'Forbidden'], 403);
        exit;
    }
}

/**
 * Resolve project root when cron HTTP scripts live under public/api/.
 */
function headcount_cron_resolve_project_root(): string
{
    if (defined('HC_PROJECT_ROOT')) {
        return HC_PROJECT_ROOT;
    }
    $hcRootDir = __DIR__ . '/..';
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);

    return $hcRootDir;
}

/**
 * Run a CLI cron script from an HTTP wrapper; returns captured stdout.
 */
function headcount_cron_run_script(string $scriptPath): string
{
    if (!is_file($scriptPath)) {
        jsonResponse(['success' => false, 'message' => 'Cron script not found: ' . basename($scriptPath)], 404);
        exit;
    }
    if (!defined('HEADCOUNT_CRON_HTTP_INCLUDE')) {
        define('HEADCOUNT_CRON_HTTP_INCLUDE', true);
    }
    ob_start();
    require $scriptPath;
    $output = trim((string) ob_get_clean());
    if ($output === '') {
        return '(completed)';
    }

    return $output;
}

/**
 * Exit unless running as CLI cron (allows HTTP wrappers to capture output).
 */
function headcount_cron_exit(int $code = 0): void
{
    if (defined('HEADCOUNT_CRON_HTTP_INCLUDE') && HEADCOUNT_CRON_HTTP_INCLUDE) {
        if ($code !== 0) {
            throw new \RuntimeException('Cron script failed with exit code ' . $code);
        }
        return;
    }
    exit($code);
}
