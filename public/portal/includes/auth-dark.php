<?php
/**
 * Dark-mode support for the standalone (header-less) portal auth pages
 * (login, register, forgot/reset password, magic-link, verify).
 *
 * These pages do not include the portal header, so they need their own
 * theme bootstrap. This:
 *   1. Applies the `.dark` class early (before paint) from the saved
 *      `headcount-portal-theme` preference, falling back to the OS setting —
 *      matching the logic in includes/header.php.
 *   2. Styles the auth-specific surfaces (body, .glass-bg card, form inputs)
 *      for dark mode, since these pages style inputs/cards outside Tailwind
 *      utilities. Tailwind `dark:` utilities on page content still apply.
 *
 * Include inside <head>, after the meta tags. No theme toggle is shown here
 * (pre-login); the page simply respects the visitor's saved/OS preference.
 */
?>
<script>
(function () {
    var K = 'headcount-portal-theme', t = null;
    try { t = localStorage.getItem(K); } catch (e) {}
    var d = t === 'dark' || (t !== 'light' && typeof matchMedia !== 'undefined' && matchMedia('(prefers-color-scheme:dark)').matches);
    document.documentElement.classList.toggle('dark', !!d);
})();
</script>
<style>
    /* ---- Light mode (these standalone pages use raw inputs, not .ta-input) ---- */
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

    /* ---- Dark mode ---- */
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
