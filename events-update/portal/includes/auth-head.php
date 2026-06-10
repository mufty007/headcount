<?php
/**
 * Shared head for portal auth / standalone pages (login, verify, payment result).
 * Expects $cssBase with trailing slash pointing at public/css/
 */
$cssBase = isset($cssBase) ? rtrim($cssBase, '/') . '/' : '/public/css/';
?>
    <script>
    (function(){var K='headcount-portal-theme';var t=null;try{t=localStorage.getItem(K);}catch(e){}
    var d=t==='dark'||(t!=='light'&&typeof matchMedia!=='undefined'&&matchMedia('(prefers-color-scheme:dark)').matches);
    document.documentElement.classList.toggle('dark',!!d);})();
    </script>
    <link rel="stylesheet" href="<?= e($cssBase) ?>tailwind-output.css">
    <link rel="stylesheet" href="<?= e($cssBase) ?>modern-design.css">
    <link rel="stylesheet" href="<?= e($cssBase) ?>modal.css">
