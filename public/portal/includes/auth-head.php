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
        /* Standalone-page surfaces (cards, inputs) — matches includes/auth-dark.php */
        .glass-bg { background: rgba(255, 255, 255, 0.8); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.3); }
        input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]),
        select,
        textarea {
            width: 100%;
            padding: 0.875rem 1rem;
            font-size: 0.95rem;
            line-height: 1.4;
            color: #111827;
            background-color: #ffffff;
            border: 1px solid #E5E7EB;
            border-radius: 0.875rem;
            transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
            outline: none;
        }
        input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):focus,
        select:focus,
        textarea:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        }
        input::placeholder, textarea::placeholder { color: #9CA3AF; }

        /* Dark mode */
        .dark body { background-color: #0f172a; }
        .dark .glass-bg { background: rgba(17, 24, 39, 0.72); border-color: rgba(148, 163, 184, 0.14); }
        .dark input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]),
        .dark select,
        .dark textarea {
            background-color: #1e293b;
            border-color: #334155;
            color: #e2e8f0;
        }
        .dark input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):focus,
        .dark select:focus,
        .dark textarea:focus {
            border-color: #818cf8;
            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2);
        }
        .dark input::placeholder,
        .dark textarea::placeholder { color: #64748b; }
    </style>
