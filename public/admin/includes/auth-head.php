<?php
/**
 * Shared head fragment for admin auth pages (login, forgot, reset).
 * Expects $cssBase pointing to the public/css/ directory URL (with trailing slash).
 */
$cssBase = isset($cssBase) ? rtrim($cssBase, '/') . '/' : '/public/css/';
?>
    <script>
    (function(){var K='headcount-admin-theme';var t=null;try{t=localStorage.getItem(K);}catch(e){}
    var d=t==='dark'||(t!=='light'&&typeof matchMedia!=='undefined'&&matchMedia('(prefers-color-scheme:dark)').matches);
    document.documentElement.classList.toggle('dark',!!d);})();
    </script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssBase); ?>tailwind-output.css">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssBase); ?>modern-design.css">
