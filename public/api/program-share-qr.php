<?php

/**
 * QR code for the public portal program URL (admin/coordinator only).
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

$programId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$programId) {
    header('Content-Type: application/json', true, 400);
    echo json_encode(['success' => false, 'message' => 'Program ID required']);
    exit;
}

$config = require __DIR__ . '/../../config/config.php';
$db = Database::getInstance($config['database']);

$program = $db->queryOne(
    'SELECT id FROM programs WHERE id = :id AND organization_id = :org_id',
    ['id' => $programId, 'org_id' => $organizationId]
);
if (!$program) {
    header('Content-Type: application/json', true, 404);
    echo json_encode(['success' => false, 'message' => 'Program not found']);
    exit;
}

$url = headcount_program_portal_url($config, $programId);
if ($url === '') {
    header('Content-Type: application/json', true, 500);
    echo json_encode(['success' => false, 'message' => 'Could not build program URL']);
    exit;
}

$download = isset($_GET['download']) && $_GET['download'] === '1';

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
            error_log('program-share-qr PNG (' . $lvl->name . '): ' . $e->getMessage());
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
            error_log('program-share-qr SVG: ' . $e->getMessage());
        }
    }
}

if ($body === null && function_exists('headcount_fetch_remote_qr_png')) {
    $remote = headcount_fetch_remote_qr_png($url);
    if ($remote !== null) {
        $body = $remote;
        $mime = 'image/png';
        $fileExt = 'png';
    }
}

if ($body === null) {
    $endpoint = 'https://api.qrserver.com/v1/create-qr-code/?size=280x280&format=png&data=' . rawurlencode($url);
    $bytes = @file_get_contents($endpoint);
    if (is_string($bytes) && strlen($bytes) > 200 && strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) {
        $body = $bytes;
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
        header('Content-Disposition: attachment; filename="program-' . $programId . '-qr.' . $fileExt . '"');
    }
    echo $body;
} catch (\Throwable $e) {
    error_log('program-share-qr output: ' . $e->getMessage());
    header('Content-Type: application/json', true, 500);
    echo json_encode(['success' => false, 'message' => 'Could not generate QR code']);
}
