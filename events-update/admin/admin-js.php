<?php
/**
 * Serve admin JS bundles with correct MIME types.
 */
declare(strict_types=1);

$allowed = [
    'event-wizard-steps.js' => 'application/javascript; charset=utf-8',
    'event-pricing-tabs.js' => 'application/javascript; charset=utf-8',
    'event-custom-questions.js' => 'application/javascript; charset=utf-8',
];

$raw = (string) ($_GET['f'] ?? '');
$file = basename(preg_replace('/\?.*$/', '', $raw));

if ($file === '' || !isset($allowed[$file])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

$adminDir = __DIR__;
$candidates = [
    $adminDir . '/js/' . $file,
    dirname($adminDir) . '/admin/js/' . $file,
];

$path = null;
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        $path = $candidate;
        break;
    }
}

if ($path === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Asset missing on server';
    exit;
}

if (!headers_sent()) {
    header('Content-Type: ' . $allowed[$file]);
    header('Cache-Control: public, max-age=2592000');
    header('X-Content-Type-Options: nosniff');
    $mtime = (int) @filemtime($path);
    if ($mtime > 0) {
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    }
}

readfile($path);
exit;
