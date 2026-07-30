<?php
/**
 * Generate PWA icons from the organization logo (or IMCA fallback tile).
 */
require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';
require_once HC_PROJECT_ROOT . '/src/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

require_once __DIR__ . '/includes/branding.php';

$size = isset($_GET['size']) ? (int) $_GET['size'] : 192;
if (!in_array($size, [180, 192, 512], true)) {
    $size = 192;
}
$maskable = !empty($_GET['maskable']);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');

if (!function_exists('imagecreatetruecolor')) {
    // Minimal 1x1 PNG if GD missing
    echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
    exit;
}

$canvas = imagecreatetruecolor($size, $size);
imagesavealpha($canvas, true);
$transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
imagefill($canvas, 0, 0, $transparent);

$hex = ltrim((string) ($themeColor ?? '#465fff'), '#');
if (!preg_match('/^[0-9A-Fa-f]{6}$/', $hex)) {
    $hex = '465fff';
}
$r = hexdec(substr($hex, 0, 2));
$g = hexdec(substr($hex, 2, 2));
$b = hexdec(substr($hex, 4, 2));
$bg = imagecolorallocate($canvas, $r, $g, $b);
imagefilledrectangle($canvas, 0, 0, $size, $size, $bg);

$logoFs = headcount_portal_logo_filesystem_path($portalBrand['logo_path'] ?? null);
$src = null;
if ($logoFs) {
    $info = @getimagesize($logoFs);
    if ($info) {
        $mime = $info['mime'] ?? '';
        if ($mime === 'image/png') {
            $src = @imagecreatefrompng($logoFs);
        } elseif ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            $src = @imagecreatefromjpeg($logoFs);
        } elseif ($mime === 'image/webp' && function_exists('imagecreatefromwebp')) {
            $src = @imagecreatefromwebp($logoFs);
        } elseif ($mime === 'image/gif') {
            $src = @imagecreatefromgif($logoFs);
        }
    }
}

// Fall back to bundled IMCA logo when org has no usable raster logo
if (!$src) {
    $defaultPng = HC_PROJECT_ROOT . '/public/assets/images/imca-logo.png';
    if (is_file($defaultPng)) {
        $src = @imagecreatefrompng($defaultPng);
    }
}

$pad = $maskable ? (int) round($size * 0.18) : (int) round($size * 0.12);
$inner = $size - (2 * $pad);

if ($src) {
    $sw = imagesx($src);
    $sh = imagesy($src);
    $scale = min($inner / max(1, $sw), $inner / max(1, $sh));
    $dw = (int) round($sw * $scale);
    $dh = (int) round($sh * $scale);
    $dx = (int) (($size - $dw) / 2);
    $dy = (int) (($size - $dh) / 2);

    // Soft white plate behind logo for contrast on brand color
    $plate = imagecolorallocatealpha($canvas, 255, 255, 255, 20);
    imagefilledellipse($canvas, (int) ($size / 2), (int) ($size / 2), $inner + 8, $inner + 8, $plate);

    imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $dw, $dh, $sw, $sh);
    imagedestroy($src);
} else {
    $white = imagecolorallocate($canvas, 255, 255, 255);
    $label = 'IMCA';
    $font = 5;
    // Built-in font is tiny; scale by drawing larger via imagestring centered
    $tw = imagefontwidth($font) * strlen($label);
    $th = imagefontheight($font);
    $scaleFactor = max(1, (int) floor($inner / max(1, $tw * 1.2)));
    // Draw a simple rounded-feel white circle + text
    $circle = imagecolorallocatealpha($canvas, 255, 255, 255, 40);
    imagefilledellipse($canvas, (int) ($size / 2), (int) ($size / 2), $inner, $inner, $circle);
    $x = (int) (($size - $tw) / 2);
    $y = (int) (($size - $th) / 2);
    imagestring($canvas, $font, $x, $y, $label, $white);
}

imagepng($canvas);
imagedestroy($canvas);
exit;
