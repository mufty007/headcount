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
    <style>
        /* Dark-mode surfaces for standalone pages (cards, inputs) — matches includes/auth-dark.php */
        .dark body { background-color: #0f172a; }
        .dark .glass-bg { background: rgba(17, 24, 39, 0.72); border-color: rgba(148, 163, 184, 0.14); }
        .dark input:not([type=checkbox]):not([type=radio]),
        .dark select,
        .dark textarea {
            background-color: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
        }
        .dark input::placeholder,
        .dark textarea::placeholder { color: #64748b; }
    </style>
