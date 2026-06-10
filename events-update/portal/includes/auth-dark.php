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
