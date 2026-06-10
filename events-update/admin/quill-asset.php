<?php

/**
 * Serve self-hosted Quill.js assets with correct MIME types.
 */

declare(strict_types=1);

$allowed = [
    'quill.js' => 'application/javascript; charset=utf-8',
    'quill.snow.css' => 'text/css; charset=utf-8',
];

$file = basename((string) ($_GET['f'] ?? ''));
if ($file === '' || !isset($allowed[$file])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found';
    exit;
}

/**
 * @return list<string>
 */
function headcountQuillAssetCandidates(string $file): array
{
    $adminDir = __DIR__;
    $candidates = [
        $adminDir . '/vendor/quill/' . $file,
        $adminDir . '/../js/vendor/quill/' . $file,
    ];

    $basePath = defined('BASE_PATH') ? BASE_PATH : dirname($adminDir, 3);
    $candidates[] = $basePath . '/node_modules/quill/dist/' . $file;
    if ($file === 'quill.js') {
        $candidates[] = $basePath . '/node_modules/quill/dist/quill.min.js';
    }

    return $candidates;
}

$path = null;
foreach (headcountQuillAssetCandidates($file) as $candidate) {
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

header('Content-Type: ' . $allowed[$file]);
header('Cache-Control: public, max-age=2592000');
header('X-Content-Type-Options: nosniff');

$mtime = (int) @filemtime($path);
if ($mtime > 0) {
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
}

readfile($path);
