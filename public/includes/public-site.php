<?php
/**
 * Shared bootstrap for public IMCA site pages (privacy, terms, support).
 * Sets path helpers and organization contact details.
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcDir = dirname(__DIR__);
    while ($hcDir !== dirname($hcDir) && !is_file($hcDir . '/vendor/autoload.php')) {
        $hcDir = dirname($hcDir);
    }
    if (!is_file($hcDir . '/vendor/autoload.php')) {
        $hcDir = dirname(__DIR__, 2);
    }
    define('HC_PROJECT_ROOT', $hcDir);
}

if (!function_exists('e')) {
    $helpersPath = HC_PROJECT_ROOT . '/src/helpers.php';
    if (is_file($helpersPath)) {
        require_once $helpersPath;
    }
}
if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$APP_NAME = 'IMCA';
$ORG_FULL_NAME = 'Indianapolis Muslim Community Association';
$ORG_WEBSITE = 'https://imcaindy.org';
$ORG_EMAIL = 'info@imcaindy.org';
$ORG_PHONE = '317-855-9934';
$ORG_PHONE_TEL = '+13178559934';
$ORG_ADDRESS = '2846 Cold Spring Rd, Indianapolis, IN 46222, United States';
$ORG_HOURS = 'Mon–Fri 9:00 AM – 5:00 PM';
$LEGAL_EFFECTIVE = 'July 30, 2026';

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/privacy.php'));
$publicWebBase = rtrim(dirname($scriptName), '/');
if ($publicWebBase === '' || $publicWebBase === '.') {
    $publicWebBase = '';
}
$assetsBase = ($publicWebBase === '' ? '' : $publicWebBase) . '/assets/';
$assetsBase = preg_replace('#/+#', '/', $assetsBase);
if ($assetsBase !== '' && $assetsBase[0] !== '/') {
    $assetsBase = '/' . $assetsBase;
}

$appBase = preg_replace('#/public$#i', '', $publicWebBase);
$appBase = rtrim((string) $appBase, '/');
$portalHome = ($appBase === '' ? '' : $appBase) . '/portal/events.php';
$portalHome = preg_replace('#/+#', '/', $portalHome);
if ($portalHome === '' || $portalHome[0] !== '/') {
    $portalHome = '/' . ltrim((string) $portalHome, '/');
}

$legalUrls = [
    'privacy' => ($publicWebBase === '' ? '' : $publicWebBase) . '/privacy.php',
    'terms' => ($publicWebBase === '' ? '' : $publicWebBase) . '/terms.php',
    'support' => ($publicWebBase === '' ? '' : $publicWebBase) . '/support.php',
];
foreach ($legalUrls as $k => $u) {
    $u = preg_replace('#/+#', '/', $u);
    if ($u === '' || $u[0] !== '/') {
        $u = '/' . ltrim((string) $u, '/');
    }
    $legalUrls[$k] = $u;
}

/**
 * Render opening HTML + chrome for a public legal/support page.
 */
function imca_public_page_start(string $pageTitle, string $current = ''): void
{
    global $APP_NAME, $assetsBase, $portalHome, $legalUrls, $ORG_WEBSITE;
    $title = $pageTitle . ' — ' . $APP_NAME;
    ?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($title) ?></title>
    <meta name="description" content="<?= e($pageTitle . ' for ' . $APP_NAME . ' community platform.') ?>">
    <meta name="theme-color" content="#0f172a">
    <link rel="icon" href="<?= e(rtrim($assetsBase, '/') . '/images/imca-logo.png') ?>">
    <script>window.tailwind = window.tailwind || {}; window.tailwind.config = { darkMode: 'class' };</script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Plus Jakarta Sans', system-ui, sans-serif; }
        .legal-prose h2 { font-size: 1.125rem; font-weight: 700; color: #0f172a; margin: 2rem 0 0.75rem; }
        .legal-prose h3 { font-size: 1rem; font-weight: 700; color: #1e293b; margin: 1.5rem 0 0.5rem; }
        .legal-prose p, .legal-prose li { color: #475569; font-size: 0.9375rem; line-height: 1.7; }
        .legal-prose p { margin-bottom: 1rem; }
        .legal-prose ul { list-style: disc; padding-left: 1.25rem; margin-bottom: 1rem; }
        .legal-prose li { margin-bottom: 0.4rem; }
        .legal-prose a { color: #2563eb; font-weight: 600; text-decoration: underline; text-underline-offset: 2px; }
        .legal-prose a:hover { color: #1d4ed8; }
    </style>
</head>
<body class="min-h-full bg-slate-50 text-slate-900 antialiased">
    <header class="border-b border-slate-200 bg-white">
        <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <a href="<?= e($portalHome) ?>" class="flex items-center gap-3 min-w-0">
                <img src="<?= e(rtrim($assetsBase, '/') . '/images/imca-logo.png') ?>" alt="<?= e($APP_NAME) ?>" class="h-10 w-10 rounded-xl object-contain bg-black" width="40" height="40">
                <span class="truncate text-lg font-extrabold tracking-tight"><?= e($APP_NAME) ?></span>
            </a>
            <nav class="flex flex-wrap items-center justify-end gap-x-4 gap-y-1 text-xs font-bold uppercase tracking-wider text-slate-500">
                <a href="<?= e($legalUrls['privacy']) ?>" class="<?= $current === 'privacy' ? 'text-slate-900' : 'hover:text-slate-800' ?>">Privacy</a>
                <a href="<?= e($legalUrls['terms']) ?>" class="<?= $current === 'terms' ? 'text-slate-900' : 'hover:text-slate-800' ?>">Terms</a>
                <a href="<?= e($legalUrls['support']) ?>" class="<?= $current === 'support' ? 'text-slate-900' : 'hover:text-slate-800' ?>">Support</a>
                <a href="<?= e($ORG_WEBSITE) ?>" class="hover:text-slate-800" target="_blank" rel="noopener noreferrer">imcaindy.org</a>
            </nav>
        </div>
    </header>
    <main class="mx-auto max-w-3xl px-4 py-10 sm:px-6 sm:py-14">
        <p class="mb-2 text-xs font-bold uppercase tracking-widest text-slate-400"><?= e($APP_NAME) ?></p>
        <h1 class="text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl"><?= e($pageTitle) ?></h1>
    <?php
}

/**
 * Render closing chrome for a public legal/support page.
 */
function imca_public_page_end(): void
{
    global $APP_NAME, $legalUrls, $ORG_WEBSITE, $ORG_EMAIL, $portalHome;
    ?>
    </main>
    <footer class="border-t border-slate-200 bg-white">
        <div class="mx-auto flex max-w-3xl flex-col gap-3 px-4 py-8 text-center text-sm text-slate-500 sm:flex-row sm:items-center sm:justify-between sm:text-left sm:px-6">
            <p>&copy; <?= date('Y') ?> <?= e($APP_NAME) ?>. All rights reserved.</p>
            <div class="flex flex-wrap items-center justify-center gap-3 text-xs font-semibold">
                <a href="<?= e($legalUrls['privacy']) ?>" class="hover:text-slate-800">Privacy</a>
                <span aria-hidden="true">·</span>
                <a href="<?= e($legalUrls['terms']) ?>" class="hover:text-slate-800">Terms</a>
                <span aria-hidden="true">·</span>
                <a href="<?= e($legalUrls['support']) ?>" class="hover:text-slate-800">Support</a>
                <span aria-hidden="true">·</span>
                <a href="<?= e($portalHome) ?>" class="hover:text-slate-800">Portal</a>
                <span aria-hidden="true">·</span>
                <a href="mailto:<?= e($ORG_EMAIL) ?>" class="hover:text-slate-800"><?= e($ORG_EMAIL) ?></a>
                <span aria-hidden="true">·</span>
                <a href="<?= e($ORG_WEBSITE) ?>" class="hover:text-slate-800" target="_blank" rel="noopener noreferrer">imcaindy.org</a>
            </div>
        </div>
    </footer>
</body>
</html>
    <?php
}
