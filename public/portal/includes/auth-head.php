<?php
/**
 * Shared head for portal auth / standalone pages (login, verify, payment result).
 * Expects $cssBase with trailing slash pointing at public/css/
 * Loads IMCA branding when available.
 */
$cssBase = isset($cssBase) ? rtrim($cssBase, '/') . '/' : '/public/css/';

if (!isset($APP_NAME) || !isset($orgLogoUrl) || !isset($themeColor)) {
    require_once __DIR__ . '/branding.php';
}
$APP_NAME = $APP_NAME ?? 'IMCA';
$orgLogoUrl = $orgLogoUrl ?? (($basePath ?? '') . '/public/assets/images/imca-logo.png');
$themeColor = $themeColor ?? '#465fff';
$pwaManifestUrl = $pwaManifestUrl ?? (($basePath ?? '') . '/manifest.php');
$pwaIconUrl = $pwaIconUrl ?? (($portalBase ?? '/portal') . '/pwa-icon.php');
$swUrl = $swUrl ?? (($basePath ?? '') . '/sw.js');
?>
    <script>
    (function(){var K='headcount-portal-theme';var t=null;try{t=localStorage.getItem(K);}catch(e){}
    var d=t==='dark'||(t!=='light'&&typeof matchMedia!=='undefined'&&matchMedia('(prefers-color-scheme:dark)').matches);
    document.documentElement.classList.toggle('dark',!!d);})();
    </script>
    <meta name="theme-color" content="<?= e($themeColor) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="IMCA">
    <meta name="application-name" content="IMCA">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="manifest" href="<?= e($pwaManifestUrl) ?>">
    <link rel="apple-touch-icon" href="<?= e($pwaIconUrl) ?>?size=180">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= e($pwaIconUrl) ?>?size=192">
    <link rel="stylesheet" href="<?= e($cssBase) ?>tailwind-output.css">
    <link rel="stylesheet" href="<?= e($cssBase) ?>modern-design.css">
    <link rel="stylesheet" href="<?= e($cssBase) ?>modal.css">
    <style>:root { --portal-accent: <?= e($themeColor) ?>; }</style>
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
        .auth-input-wrap { position: relative; }
        .auth-input-wrap > input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]) {
            padding-left: 2.75rem;
            padding-right: 1rem;
        }
        .auth-input-icon {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 2.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
            color: #9CA3AF;
        }
        .auth-input-icon svg { width: 1.25rem; height: 1.25rem; display: block; }
        .auth-honeypot {
            position: absolute !important;
            left: -10000px !important;
            top: auto !important;
            width: 1px !important;
            height: 1px !important;
            overflow: hidden !important;
            opacity: 0 !important;
            pointer-events: none !important;
        }
        input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):focus,
        select:focus,
        textarea:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.15);
        }
        input::placeholder, textarea::placeholder { color: #9CA3AF; }
        input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):-webkit-autofill,
        input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):-webkit-autofill:hover,
        input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):-webkit-autofill:focus {
            -webkit-text-fill-color: #111827;
            -webkit-box-shadow: 0 0 0 1000px #ffffff inset;
            box-shadow: 0 0 0 1000px #ffffff inset;
            transition: background-color 99999s ease-in-out 0s;
        }

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
        .dark .auth-input-icon { color: #64748b; }
        .dark input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):focus,
        .dark select:focus,
        .dark textarea:focus {
            border-color: #818cf8;
            box-shadow: 0 0 0 3px rgba(129, 140, 248, 0.2);
        }
        .dark input::placeholder,
        .dark textarea::placeholder { color: #64748b; }
        .dark input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):-webkit-autofill,
        .dark input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):-webkit-autofill:hover,
        .dark input:not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):-webkit-autofill:focus {
            -webkit-text-fill-color: #e2e8f0;
            -webkit-box-shadow: 0 0 0 1000px #1e293b inset;
            box-shadow: 0 0 0 1000px #1e293b inset;
        }
    </style>
