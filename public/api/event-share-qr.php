<?php

/**
 * QR code for the public portal event URL (admin/coordinator only).
 *
 * Strategy: PNG (GD) → SVG (Endroid, no GD) → remote PNG (api.qrserver.com) if local libs fail.
 * Remote fallback sends the public event URL to a third-party QR API (same data as the QR would encode).
 */

declare(strict_types=1);

ini_set('display_errors', '0');

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;

AuthMiddleware::requireAdminOrCoordinator();
$organizationId = AuthMiddleware::getOrganizationId();

$eventId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$eventId) {
    header('Content-Type: application/json', true, 400);
    echo json_encode(['success' => false, 'message' => 'Event ID required']);
    exit;
}

$config = require HC_PROJECT_ROOT . '/config/config.php';
$db = Database::getInstance($config['database']);

$event = $db->queryOne(
    'SELECT id FROM events WHERE id = :id AND organization_id = :org_id',
    ['id' => $eventId, 'org_id' => $organizationId]
);
if (!$event) {
    header('Content-Type: application/json', true, 404);
    echo json_encode(['success' => false, 'message' => 'Event not found']);
    exit;
}

$url = headcount_event_portal_url($config, $eventId);
if ($url === '') {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['success' => false, 'message' => 'Could not build event URL']);
    exit;
}

$download = isset($_GET['download']) && $_GET['download'] === '1';

/**
 * @return \Endroid\QrCode\Writer\Result\ResultInterface|null
 */
$buildLocalQr = static function (string $data, $writer, int $size = 280, $level = null) {
    $b = Builder::create()
        ->writer($writer)
        ->data($data)
        ->encoding(new Encoding('UTF-8'))
        ->size($size)
        ->margin(10);
    if ($level !== null) {
        $b = $b->errorCorrectionLevel($level);
    } else {
        $b = $b->errorCorrectionLevel(ErrorCorrectionLevel::High);
    }
    return $b->build();
};

$body = null;
$mime = null;
$fileExt = null;

$gdAvailable = extension_loaded('gd') && function_exists('imagecreatetruecolor');

if ($gdAvailable) {
    foreach ([ErrorCorrectionLevel::High, ErrorCorrectionLevel::Medium] as $lvl) {
        try {
            $result = $buildLocalQr($url, new PngWriter(), 280, $lvl);
            $body = $result->getString();
            $mime = 'image/png';
            $fileExt = 'png';
            break;
        } catch (\Throwable $e) {
            error_log('event-share-qr PNG (' . $lvl->name . '): ' . $e->getMessage());
        }
    }
}

if ($body === null) {
    foreach (
        [
            [new SvgWriter(), 280, ErrorCorrectionLevel::High],
            [new SvgWriter(), 280, ErrorCorrectionLevel::Low],
            [new SvgWriter(), 200, ErrorCorrectionLevel::Low],
        ] as $try
    ) {
        try {
            [$writer, $size, $lvl] = $try;
            $result = $buildLocalQr($url, $writer, $size, $lvl);
            $body = $result->getString();
            $mime = 'image/svg+xml';
            $fileExt = 'svg';
            break;
        } catch (\Throwable $e) {
            error_log('event-share-qr SVG: ' . $e->getMessage());
        }
    }
}

if ($body === null) {
    $remote = headcount_fetch_remote_qr_png($url);
    if ($remote !== null) {
        $body = $remote;
        $mime = 'image/png';
        $fileExt = 'png';
    }
}

if ($body === null) {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['success' => false, 'message' => 'Could not generate QR code']);
    exit;
}

try {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: ' . $mime);
    header('Cache-Control: private, max-age=300');
    if ($download) {
        header('Content-Disposition: attachment; filename="event-' . $eventId . '-qr.' . $fileExt . '"');
    }
    echo $body;
} catch (\Throwable $e) {
    error_log('event-share-qr output: ' . $e->getMessage());
    header('Content-Type: application/json', true, 500);
    echo json_encode(['success' => false, 'message' => 'Could not generate QR code']);
}

/**
 * Last-resort: PNG from public QR API (requires outbound HTTPS).
 */
function headcount_fetch_remote_qr_png(string $data): ?string
{
    $endpoint = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&format=png&data=' . rawurlencode($data);

    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        if ($ch !== false) {
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 12,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER => ['User-Agent: Headcount-EventQR/1.0'],
            ]);
            $bytes = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if (is_string($bytes) && $code >= 200 && $code < 300 && headcount_is_png_bytes($bytes)) {
                return $bytes;
            }
            error_log('event-share-qr remote(curl): HTTP ' . $code . ' len=' . (is_string($bytes) ? strlen($bytes) : 0));
        }
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 12,
            'user_agent' => 'Headcount-EventQR/1.0',
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $bytes = @file_get_contents($endpoint, false, $ctx);
    if (is_string($bytes) && headcount_is_png_bytes($bytes)) {
        return $bytes;
    }
    error_log('event-share-qr remote(file_get_contents): failed or invalid PNG');

    return null;
}

function headcount_is_png_bytes(string $bytes): bool
{
    return strlen($bytes) > 200 && strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0;
}
