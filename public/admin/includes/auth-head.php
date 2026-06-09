<?php
/**
 * Shared head fragment for admin auth pages (login, forgot, reset).
 * Expects $cssBase pointing to the public/css/ directory URL (with trailing slash).
 */
$cssBase = isset($cssBase) ? rtrim($cssBase, '/') . '/' : '/public/css/';

// Cache-bust by file mtime so a rebuilt stylesheet is never served stale on auth
// pages (these have no other versioning, unlike the main admin header).
if (!function_exists('authHeadCssVersion')) {
    function authHeadCssVersion(string $file): string
    {
        $path = realpath(__DIR__ . '/../../css') . DIRECTORY_SEPARATOR . $file;
        return is_file($path) ? '?v=' . (int) @filemtime($path) : '';
    }
}
?>
    <script>
    (function(){var K='headcount-admin-theme';var t=null;try{t=localStorage.getItem(K);}catch(e){}
    var d=t==='dark'||(t!=='light'&&typeof matchMedia!=='undefined'&&matchMedia('(prefers-color-scheme:dark)').matches);
    document.documentElement.classList.toggle('dark',!!d);})();
    </script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssBase); ?>tailwind-output.css<?php echo authHeadCssVersion('tailwind-output.css'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssBase); ?>modern-design.css<?php echo authHeadCssVersion('modern-design.css'); ?>">
