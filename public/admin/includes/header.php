<!DOCTYPE html>
<html lang="en" class="h-full">
<?php
// Include helper functions
if (!function_exists('e')) {
    require_once __DIR__ . '/../../../src/helpers.php';
}

// Centralized layout variables (basePath, adminBase, assetsBase, navUrls, currentPage)
require_once __DIR__ . '/layout-vars.php';

if (!isset($currentPage)) {
    $currentPage = $_GET['page'] ?? 'dashboard';
}

// Ensure $user is available for the header
if (!isset($user)) {
    // Try to get from session (AuthController sets 'name' and 'email', not 'user_name'/'user_email')
    $user = [
        'name' => $_SESSION['name'] ?? trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Administrator',
        'email' => $_SESSION['email'] ?? 'admin@headcount.local',
        'role' => $_SESSION['role'] ?? 'admin'
    ];
}
if (!isset($user['role'])) {
    $user['role'] = $_SESSION['role'] ?? 'admin';
}
$isCoordinator = (isset($user['role']) && $user['role'] === 'coordinator');

$emailTemplatesNavActive = ($currentPage === 'email-templates');
$campaignsNavActive = ($currentPage === 'email-campaigns');

// Ensure $APP_NAME is available
if (!isset($APP_NAME)) {
    $APP_NAME = 'Headcount';
}

// $basePath, $adminBase, $assetsBase, $navUrls, $currentPage come from layout-vars.php
?>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    (function(){var K='headcount-admin-theme';var t=null;try{t=localStorage.getItem(K);}catch(e){}
    var d=t==='dark'||(t!=='light'&&typeof matchMedia!=='undefined'&&matchMedia('(prefers-color-scheme:dark)').matches);
    document.documentElement.classList.toggle('dark',!!d);})();
    </script>
    <!-- Alpine x-cloak: must load before any deferred Alpine so hidden panels/modals do not flash when using compiled Tailwind without modern-design.css -->
    <style>[x-cloak]{display:none!important}</style>
    <title><?php echo htmlspecialchars($pageTitle ?? 'Dashboard'); ?> - <?php echo htmlspecialchars($APP_NAME); ?></title>
    <script>
    document.addEventListener('alpine:init', function () {
        Alpine.data('adminShell', function () {
            return {
                sidebarOpen: false,
                sidebarCollapsed: false,
                themeMenuOpen: false,
                theme: 'system',
                init: function () {
                    if (typeof Storage !== 'undefined') {
                        this.sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
                        this.theme = localStorage.getItem('headcount-admin-theme') || 'system';
                    }
                    this.applyTheme();
                    var self = this;
                    if (typeof matchMedia !== 'undefined') {
                        matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
                            if (self.theme === 'system') self.applyTheme();
                        });
                    }
                },
                isDark: function () {
                    return this.theme === 'dark' || (this.theme === 'system' && typeof matchMedia !== 'undefined' && matchMedia('(prefers-color-scheme: dark)').matches);
                },
                applyTheme: function () {
                    document.documentElement.classList.toggle('dark', this.isDark());
                    window.dispatchEvent(new CustomEvent('headcount-theme-change', { detail: { dark: this.isDark() } }));
                },
                setTheme: function (mode) {
                    this.theme = mode;
                    if (typeof Storage !== 'undefined') {
                        localStorage.setItem('headcount-admin-theme', mode);
                    }
                    this.applyTheme();
                    this.themeMenuOpen = false;
                },
                toggleSidebar: function () {
                    this.sidebarCollapsed = !this.sidebarCollapsed;
                    if (typeof Storage !== 'undefined') {
                        localStorage.setItem('sidebarCollapsed', this.sidebarCollapsed);
                    }
                }
            };
        });
    });
    </script>
    <!-- Fonts & Utilities -->
    <script src="https://unpkg.com/alpinejs@3.13.5/dist/cdn.min.js" defer></script>
    <!-- Quill.js WYSIWYG: loaded per-page via $additionalCSS / $additionalJS on pages that use editors -->
    <style>
        .ql-toolbar.ql-snow {
            border-top-left-radius: 0.75rem;
            border-top-right-radius: 0.75rem;
            border-color: #E2E8F0;
            background: #F8FAFC;
        }
        .ql-container.ql-snow {
            border-bottom-left-radius: 0.75rem;
            border-bottom-right-radius: 0.75rem;
            border-color: #E2E8F0;
            font-family: inherit;
            font-size: 0.95rem;
        }
        .ql-editor {
            min-height: 150px;
        }
        html.dark .ql-toolbar.ql-snow,
        .dark .ql-toolbar.ql-snow {
            border-color: #475569 !important;
            background: #1e293b !important;
        }
        html.dark .ql-container.ql-snow,
        .dark .ql-container.ql-snow {
            border-color: #475569 !important;
            background: #0f172a !important;
        }
        html.dark .ql-snow .ql-stroke,
        .dark .ql-snow .ql-stroke { stroke: #cbd5e1 !important; }
        html.dark .ql-snow .ql-fill,
        .dark .ql-snow .ql-fill { fill: #cbd5e1 !important; }
        html.dark .ql-snow .ql-picker,
        .dark .ql-snow .ql-picker { color: #e2e8f0 !important; }
        html.dark .ql-snow .ql-picker-label,
        .dark .ql-snow .ql-picker-label { color: inherit !important; }
        html.dark .ql-snow .ql-picker-options,
        .dark .ql-snow .ql-picker-options { background: #1e293b !important; border-color: #475569 !important; }
        html.dark .ql-editor,
        .dark .ql-editor {
            color: #e2e8f0 !important;
        }
        html.dark .ql-snow .ql-editor p,
        html.dark .ql-snow .ql-editor li,
        html.dark .ql-snow .ql-editor h1,
        html.dark .ql-snow .ql-editor h2,
        html.dark .ql-snow .ql-editor h3,
        .dark .ql-snow .ql-editor p,
        .dark .ql-snow .ql-editor li,
        .dark .ql-snow .ql-editor h1,
        .dark .ql-snow .ql-editor h2,
        .dark .ql-snow .ql-editor h3 {
            color: #e2e8f0 !important;
        }
        html.dark .ql-snow .ql-editor.ql-blank::before,
        .dark .ql-snow .ql-editor.ql-blank::before {
            color: #64748b !important;
        }
    </style>
    <?php
    if (!function_exists('buildJsPath')) {
        function buildJsPathHeader($basePath, $filename) {
            if (empty($basePath) || $basePath === '/') {
                $jsPath = '/public/js/' . $filename;
            } else {
                $jsPath = ($basePath[0] !== '/') ? '/' . $basePath : $basePath;
                $jsPath = rtrim($jsPath, '/') . '/public/js/' . $filename;
            }
            $jsPath = preg_replace('#/+#', '/', $jsPath);
            if ($jsPath[0] !== '/') $jsPath = '/' . $jsPath;
            return $jsPath;
        }
    } else {
        function buildJsPathHeader($basePath, $filename) { return buildJsPath($basePath, $filename); }
    }
    echo '<script src="' . e(buildJsPathHeader($basePath, 'admin-app.js')) . '"></script>' . "\n    ";
    ?>
    <?php
    // Admin CSS lives under public/css. Derive its URL from the real directory vs DOCUMENT_ROOT
    // so links stay correct when $basePath from layout detection disagrees with the web root.
    $hcAdminCssDirFs = realpath(__DIR__ . '/../../css');
    $hcDocRootFs = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $hcPublicCssWebBase = null;
    if ($hcAdminCssDirFs && $hcDocRootFs) {
        $hcCssNorm = str_replace('\\', '/', $hcAdminCssDirFs);
        $hcRootNorm = str_replace('\\', '/', $hcDocRootFs);
        if (strpos($hcCssNorm, $hcRootNorm) === 0) {
            $hcRel = substr($hcCssNorm, strlen($hcRootNorm));
            $hcPublicCssWebBase = '/' . trim($hcRel, '/');
        }
    }
    if ($hcPublicCssWebBase === null || $hcPublicCssWebBase === '') {
        if (empty($basePath) || $basePath === '/') {
            $hcPublicCssWebBase = '/public/css';
        } else {
            $basePathFormatted = ($basePath[0] !== '/') ? '/' . $basePath : $basePath;
            $hcPublicCssWebBase = rtrim(preg_replace('#/+#', '/', $basePathFormatted), '/') . '/public/css';
        }
    }
    $hcPublicCssWebBase = preg_replace('#/+#', '/', $hcPublicCssWebBase);
    if ($hcPublicCssWebBase === '' || ($hcPublicCssWebBase[0] ?? '') !== '/') {
        $hcPublicCssWebBase = '/' . ltrim((string) $hcPublicCssWebBase, '/');
    }

    $tailwindPath = $hcPublicCssWebBase . '/tailwind-output.css';
    $modernPath = $hcPublicCssWebBase . '/modern-design.css';
    $modalPath = $hcPublicCssWebBase . '/modal.css';

    $tailwindFileFs = ($hcAdminCssDirFs && is_dir($hcAdminCssDirFs)) ? $hcAdminCssDirFs . DIRECTORY_SEPARATOR . 'tailwind-output.css' : '';
    $modernFileFs = ($hcAdminCssDirFs && is_dir($hcAdminCssDirFs)) ? $hcAdminCssDirFs . DIRECTORY_SEPARATOR . 'modern-design.css' : '';
    $modalFileFs = ($hcAdminCssDirFs && is_dir($hcAdminCssDirFs)) ? $hcAdminCssDirFs . DIRECTORY_SEPARATOR . 'modal.css' : '';

    $tailwindV = ($tailwindFileFs !== '' && is_file($tailwindFileFs)) ? (int) @filemtime($tailwindFileFs) : 0;
    $modernV = ($modernFileFs !== '' && is_file($modernFileFs)) ? (int) @filemtime($modernFileFs) : 0;
    $modalV = ($modalFileFs !== '' && is_file($modalFileFs)) ? (int) @filemtime($modalFileFs) : 0;

    $tailwindHref = $tailwindPath . ($tailwindV ? '?v=' . $tailwindV : '');
    $modernHref = $modernPath . ($modernV ? '?v=' . $modernV : '');
    $modalHref = $modalPath . ($modalV ? '?v=' . $modalV : '');

    if ($tailwindFileFs !== '' && is_file($tailwindFileFs)) {
        echo '<link rel="stylesheet" href="' . e($tailwindHref) . '">' . "\n    ";
    } else {
        echo '<script src="https://cdn.tailwindcss.com"></script>' . "\n    ";
    }
    echo '<link rel="stylesheet" href="' . e($modernHref) . '">' . "\n    ";
    if (!empty($adminMainFullWidth)) {
        echo '<style id="admin-event-wizard-layout">'
            . 'body.admin-layout-full-width{padding:0!important}'
            . 'body.admin-layout-full-width .main-content,body.admin-layout-full-width .main-content>main{width:100%!important;max-width:none!important}'
            . 'body.admin-layout-full-width .main-content>main.admin-main-padded{padding:1.5rem!important;box-sizing:border-box}'
            . 'body.admin-layout-full-width .main-content>main>.admin-main-full-width{width:100%!important;max-width:100%!important;margin:0!important;padding:0!important}'
            . 'body.admin-layout-full-width .admin-event-wizard,body.admin-layout-full-width .admin-event-wizard>form,body.admin-layout-full-width .admin-event-wizard .admin-form-card,body.admin-layout-full-width .admin-event-wizard .step-panel,body.admin-layout-full-width .admin-event-wizard .event-step,body.admin-layout-full-width .admin-event-wizard .multi-step-progress{width:100%!important;max-width:100%!important;box-sizing:border-box}'
            . 'body.admin-layout-full-width .admin-event-wizard .ql-toolbar,body.admin-layout-full-width .admin-event-wizard .ql-container,body.admin-layout-full-width .admin-event-wizard .ql-editor{width:100%!important;max-width:100%!important}'
            . '</style>' . "\n    ";
    }
    if (!empty($requiresEventWizard)) {
        if (!function_exists('headcount_admin_js_emit')) {
            require_once __DIR__ . '/../../../src/helpers.php';
        }
        headcount_admin_js_emit('event-wizard-steps.js?v=8');
        headcount_admin_js_emit('event-pricing-tabs.js?v=5');
    }
    ?>

    <?php
    echo '<link rel="stylesheet" href="' . e($modalHref) . '">' . "\n    ";
    
    // Allow pages to add additional CSS files
    if (isset($additionalCSS) && is_array($additionalCSS)) {
        foreach ($additionalCSS as $css) {
            if (is_string($css) && stripos($css, 'quill') !== false) {
                continue;
            }
            echo '<link rel="stylesheet" href="' . htmlspecialchars($css) . '">' . "\n    ";
        }
    }

    if (!function_exists('headcountQuillHasLocalAssets')) {
        function headcountQuillHasLocalAssets(): bool
        {
            $paths = [
                __DIR__ . '/../vendor/quill/quill.js',
                __DIR__ . '/../../js/vendor/quill/quill.js',
            ];
            foreach ($paths as $path) {
                if (is_file($path)) {
                    return true;
                }
            }
            $basePath = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
            return is_file($basePath . '/node_modules/quill/dist/quill.js')
                || is_file($basePath . '/node_modules/quill/dist/quill.min.js');
        }
    }

    if (!function_exists('headcountQuillAssetUrl')) {
        function headcountQuillAssetUrl($filename) {
            global $adminBase;
            $map = [
                'quill.js' => 'quill.js',
                'quill.snow.css' => 'quill.snow.css',
            ];
            $file = $map[ltrim((string) $filename, '/')] ?? '';
            if ($file === '') {
                return '';
            }
            $base = isset($adminBase) ? rtrim((string) $adminBase, '/') : '/admin';
            return $base . '/?' . http_build_query(['page' => 'quill-asset', 'f' => $file]);
        }
    }

    $headcountNeedsQuill = !empty($requiresQuillEditor);
    if (!$headcountNeedsQuill && !empty($additionalJS) && is_array($additionalJS)) {
        foreach ($additionalJS as $jsUrl) {
            if (is_string($jsUrl) && stripos($jsUrl, 'quill') !== false) {
                $headcountNeedsQuill = true;
                break;
            }
        }
    }

    if ($headcountNeedsQuill) {
        if (headcountQuillHasLocalAssets()) {
            $quillVendorFs = __DIR__ . '/../vendor/quill/quill.js';
            $quillVer = is_file($quillVendorFs) ? (int) @filemtime($quillVendorFs) : 0;
            $quillCssHref = headcountQuillAssetUrl('quill.snow.css');
            $quillJsSrc = headcountQuillAssetUrl('quill.js');
            if ($quillVer) {
                $quillCssHref .= '&v=' . $quillVer;
                $quillJsSrc .= '&v=' . $quillVer;
            }
        } else {
            $quillCssHref = 'https://cdn.quilljs.com/1.3.6/quill.snow.css';
            $quillJsSrc = 'https://cdn.quilljs.com/1.3.6/quill.js';
        }
        echo '<link rel="stylesheet" href="' . e($quillCssHref) . '">' . "\n    ";
        echo '<script src="' . e($quillJsSrc) . '"></script>' . "\n    ";
    }
    ?>
    <script>
    window.__quillInstances = window.__quillInstances || new Map();
    window.initWYSIWYG = function(selector, options) {
        options = options || {};
        if (typeof Quill === 'undefined') {
            return;
        }
        document.querySelectorAll(selector).forEach(function(el) {
            if (el.dataset.quillInitialized || window.__quillInstances.has(el)) {
                return;
            }
            el.dataset.quillInitialized = 'true';
            el.style.display = 'none';

            var container = document.createElement('div');
            el.parentNode.insertBefore(container, el.nextSibling);

            var quill;
            try {
                quill = new Quill(container, {
                    theme: 'snow',
                    placeholder: el.getAttribute('placeholder') || 'Type here...',
                    modules: {
                        toolbar: [
                            ['bold', 'italic', 'underline', 'strike'],
                            [{ list: 'ordered' }, { list: 'bullet' }],
                            ['link', 'clean']
                        ]
                    }
                });
            } catch (err) {
                console.error('Quill init failed:', err);
                el.style.display = '';
                container.remove();
                delete el.dataset.quillInitialized;
                return;
            }

            window.__quillInstances.set(el, quill);
            quill.root.innerHTML = el.value || '';

            quill.on('text-change', function() {
                var html = quill.root.innerHTML;
                var cleanHtml = (html === '\u003cp\u003e\u003cbr\u003e\u003c/p\u003e') ? '' : html;
                el.value = cleanHtml;
                if (options.onChange) {
                    options.onChange(cleanHtml);
                }
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            });

            el.addEventListener('sync-to-quill', function() {
                if (quill.root.innerHTML !== el.value) {
                    quill.root.innerHTML = el.value || '';
                }
            });
        });
    };

    function headcountBootWysiwygEditors() {
        if (typeof Quill === 'undefined' || typeof window.initWYSIWYG !== 'function') {
            return false;
        }
        window.initWYSIWYG('.wysiwyg-editor');
        return true;
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', headcountBootWysiwygEditors);
    } else {
        headcountBootWysiwygEditors();
    }
    window.addEventListener('load', headcountBootWysiwygEditors);
    </script>
</head>
<body class="h-full overflow-hidden flex min-h-0 flex-col antialiased text-gray-900 dark:text-slate-100<?= !empty($adminMainFullWidth) ? ' admin-layout-full-width' : '' ?>" x-data="adminShell">
    <div class="app-container flex min-h-0 flex-1 flex-row overflow-hidden">
        
        <!-- Mobile Sidebar Overlay -->
        <div x-show="sidebarOpen" 
             @click="sidebarOpen = false" 
             class="fixed inset-0 z-40 bg-gray-900/50 backdrop-blur-[1px] transition-opacity duration-300 dark:bg-black/60 lg:hidden"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"></div>

        <!-- Sidebar Navigation -->
        <aside class="sidebar border-gray-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900 lg:shadow-none" :class="{ 'open': sidebarOpen, 'collapsed': sidebarCollapsed }" role="navigation" aria-label="Main navigation">
            <div class="sidebar-header flex items-center justify-between border-gray-200 dark:border-slate-700">
                <a href="<?= e($navUrls['dashboard']) ?>" class="flex min-w-0 flex-1 items-center gap-3">
                    <img src="<?= e($assetsBase) ?>images/logo.svg" alt="Logo" class="h-9 w-9 shrink-0 rounded-lg">
                    <span class="sidebar-title text-xl font-bold tracking-tight text-gray-900 dark:text-white">Headcount</span>
                </a>
                <div class="flex shrink-0 items-center gap-1">
                    <button type="button" @click="toggleSidebar()" :aria-expanded="!sidebarCollapsed" aria-controls="sidebar-nav" class="hidden rounded-lg p-2 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white lg:flex" title="Toggle sidebar width" aria-label="Toggle sidebar">
                        <svg x-show="!sidebarCollapsed" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path></svg>
                        <svg x-show="sidebarCollapsed" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path></svg>
                    </button>
                    <button type="button" @click="sidebarOpen = false" class="rounded-lg p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-900 dark:text-slate-400 dark:hover:bg-slate-800 dark:hover:text-white lg:hidden" aria-label="Close menu">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
            </div>

            <nav class="sidebar-nav no-scrollbar" id="sidebar-nav" aria-label="Site pages">
                <a href="<?= e($navUrls['dashboard']) ?>" class="nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>" title="Dashboard">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
                    <span class="nav-label">Dashboard</span>
                </a>
                
                <a href="<?= e($navUrls['events']) ?>" class="nav-item <?= in_array($currentPage, ['events', 'event-create', 'event-edit', 'event-details'], true) ? 'active' : '' ?>" title="Events">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    <span class="nav-label">Events</span>
                </a>

                <div class="nav-section-label mb-2 mt-6 px-3 text-[0.65rem] font-semibold uppercase leading-5 tracking-wider text-gray-400 dark:text-slate-500">Programs</div>

                <a href="<?= e($navUrls['programs']) ?>" class="nav-item <?= ($currentPage === 'programs' || $currentPage === 'program-edit') ? 'active' : '' ?> mt-1" title="Programs">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"></path></svg>
                    <span class="nav-label">Programs</span>
                </a>
                <a href="<?= e($navUrls['program-attendance']) ?>" class="nav-item <?= $currentPage === 'program-attendance' ? 'active' : '' ?>" title="Program attendance — sessions &amp; roster">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path></svg>
                    <span class="nav-label">Attendance</span>
                </a>

                <div class="nav-section-label mb-2 mt-6 px-3 text-[0.65rem] font-semibold uppercase leading-5 tracking-wider text-gray-400 dark:text-slate-500">Facilities</div>

                <a href="<?= e($navUrls['facilities'] ?? (rtrim($adminBase, '/') . '/?page=facilities')) ?>" class="nav-item <?= in_array($currentPage, ['facilities', 'facility-edit', 'facility-details'], true) ? 'active' : '' ?> mt-1" title="Facilities">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>
                    <span class="nav-label">Facilities</span>
                </a>
                <a href="<?= e($navUrls['facility-bookings'] ?? (rtrim($adminBase, '/') . '/?page=facility-bookings')) ?>" class="nav-item <?= $currentPage === 'facility-bookings' ? 'active' : '' ?>" title="Facility bookings">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                    <span class="nav-label">Bookings</span>
                </a>
                
                <?php if (!$isCoordinator): ?>
                <a href="<?= e($navUrls['members']) ?>" class="nav-item <?= $currentPage === 'members' ? 'active' : '' ?>" title="Members">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                    <span class="nav-label">Members</span>
                </a>
                <?php endif; ?>
                
                <a href="<?= e($navUrls['checkin']) ?>" class="nav-item <?= $currentPage === 'checkin' ? 'active' : '' ?>" title="Check-In">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span class="nav-label">Check-In</span>
                </a>
                
                <a href="<?= e($navUrls['reports']) ?>" class="nav-item <?= $currentPage === 'reports' ? 'active' : '' ?>" title="Reports">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path></svg>
                    <span class="nav-label">Reports</span>
                </a>
                
                <?php if (!$isCoordinator): ?>
                <a href="<?= e($navUrls['payment-transfers']) ?>" class="nav-item <?= $currentPage === 'payment-transfers' ? 'active' : '' ?>" title="Payments">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    <span class="nav-label">Payments</span>
                </a>
                
                <a href="<?= e($navUrls['notifications']) ?>" class="nav-item <?= $currentPage === 'notifications' ? 'active' : '' ?>" title="Notifications">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                    <span class="nav-label">Notifications</span>
                </a>
                
                <a href="<?= e($navUrls['activity-log']) ?>" class="nav-item <?= $currentPage === 'activity-log' ? 'active' : '' ?>" title="Activity Log">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    <span class="nav-label">Activity Log</span>
                </a>
                
                <a href="<?= e($navUrls['refund-requests']) ?>" class="nav-item <?= $currentPage === 'refund-requests' ? 'active' : '' ?>" title="Refund Requests">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"></path></svg>
                    <span class="nav-label">Refund Requests</span>
                </a>

                <div class="nav-section-label mb-2 mt-8 px-3 text-[0.65rem] font-semibold uppercase leading-5 tracking-wider text-gray-400 dark:text-slate-500">System</div>
                
                <a href="<?= e($navUrls['email-templates'] ?? ($adminBase . '/index.php?page=email-templates')) ?>" class="nav-item <?= $emailTemplatesNavActive ? 'active' : '' ?> mt-2" title="Email templates">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                    <span class="nav-label">Email Templates</span>
                </a>
                <a href="<?= e($navUrls['campaigns']) ?>" class="nav-item <?= $campaignsNavActive ? 'active' : '' ?>" title="Campaigns &amp; send email">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path></svg>
                    <span class="nav-label">Campaigns</span>
                </a>
                <a href="<?= e($navUrls['settings']) ?>" class="nav-item <?= $currentPage === 'settings' ? 'active' : '' ?>" title="Settings">
                    <svg class="nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path></svg>
                    <span class="nav-label">Settings</span>
                </a>
                <?php endif; ?>
            </nav>

            <div class="mt-auto border-t border-gray-200 p-4 dark:border-slate-700">
                <a href="<?= e($adminBase . '/?page=logout') ?>" class="flex items-center gap-3 rounded-lg p-2 text-sm font-medium text-red-600 transition-colors hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40" title="Sign Out">
                    <svg class="w-5 h-5 nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                    <span class="nav-label">Sign Out</span>
                </a>
            </div>
        </aside>

        <!-- Main Content Wrapper -->
        <div class="main-content flex h-screen min-h-0 flex-col overflow-hidden" :class="{ 'sidebar-collapsed': sidebarCollapsed }">
            
            <!-- Top header -->
            <header class="sticky top-0 z-30 flex h-16 w-full shrink-0 items-center gap-2 border-b border-gray-200 bg-white px-4 dark:border-slate-700 dark:bg-slate-900 sm:gap-4 sm:px-6">
                <div class="flex shrink-0 items-center gap-3 sm:gap-4">
                    <button type="button" @click="sidebarOpen = true" :aria-expanded="sidebarOpen" aria-controls="sidebar-nav" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800 lg:hidden" aria-label="Open menu">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                    </button>
                    <h2 class="truncate text-lg font-semibold text-gray-800 dark:text-slate-100 lg:hidden">Headcount</h2>
                </div>

                <div class="hidden min-w-0 flex-1 justify-center px-2 md:flex">
                    <div class="flex w-full max-w-xl items-center rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-slate-600 dark:bg-slate-800/80">
                        <svg class="mr-2 h-4 w-4 shrink-0 text-gray-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        <input type="search" placeholder="Quick search" class="w-full border-0 bg-transparent text-sm text-gray-800 placeholder-gray-400 focus:outline-none focus:ring-0 dark:text-slate-200 dark:placeholder-slate-500" autocomplete="off">
                    </div>
                </div>

                <div class="ml-auto flex shrink-0 items-center gap-2 sm:gap-4 md:ml-0">
                    <!-- Theme -->
                    <div class="relative" @click.outside="themeMenuOpen = false">
                        <button
                            type="button"
                            @click="themeMenuOpen = !themeMenuOpen"
                            :aria-expanded="themeMenuOpen"
                            aria-haspopup="true"
                            aria-label="Color theme"
                            class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-gray-600 transition-colors hover:bg-gray-50 dark:border-slate-600 dark:text-slate-300 dark:hover:bg-slate-800"
                        >
                            <svg x-show="!isDark()" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                            <svg x-show="isDark()" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" x-cloak><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path></svg>
                        </button>
                        <div
                            x-show="themeMenuOpen"
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 scale-95"
                            x-transition:enter-end="opacity-100 scale-100"
                            x-transition:leave="transition ease-in duration-100"
                            x-transition:leave-start="opacity-100 scale-100"
                            x-transition:leave-end="opacity-0 scale-95"
                            class="absolute right-0 z-50 mt-2 w-44 rounded-xl border border-gray-200 bg-white py-1 shadow-theme-lg dark:border-slate-600 dark:bg-slate-800"
                            style="display: none;"
                            role="menu"
                        >
                            <button type="button" role="menuitem" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-700/80" :class="{ 'bg-indigo-50 font-semibold text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300': theme === 'light' }" @click="setTheme('light')">Light</button>
                            <button type="button" role="menuitem" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-700/80" :class="{ 'bg-indigo-50 font-semibold text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300': theme === 'dark' }" @click="setTheme('dark')">Dark</button>
                            <button type="button" role="menuitem" class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-gray-700 hover:bg-gray-50 dark:text-slate-200 dark:hover:bg-slate-700/80" :class="{ 'bg-indigo-50 font-semibold text-indigo-700 dark:bg-indigo-950/50 dark:text-indigo-300': theme === 'system' }" @click="setTheme('system')">System</button>
                        </div>
                    </div>
                    <!-- Notifications Dropdown -->
                    <div class="relative" x-data="notificationDropdown()" @click.outside="open = false">
                        <button
                            @click="open = !open; if (open) loadNotifications()"
                            :aria-expanded="open"
                            aria-haspopup="true"
                            aria-label="Notifications"
                            @keydown.escape.window="open = false"
                            class="relative p-2 text-gray-400 transition-colors hover:text-gray-600 dark:text-slate-400 dark:hover:text-slate-200"
                        >
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                            </svg>
                            <span 
                                x-show="unreadCount > 0" 
                                x-text="unreadCount > 99 ? '99+' : unreadCount"
                                class="absolute right-1 top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full border-2 border-white bg-red-500 px-1 text-[10px] font-bold text-white dark:border-slate-800"
                            ></span>
                        </button>
                        
                        <!-- Dropdown Panel -->
                        <div 
                            x-show="open"
                            x-transition:enter="transition ease-out duration-200"
                            x-transition:enter-start="opacity-0 transform scale-95"
                            x-transition:enter-end="opacity-100 transform scale-100"
                            x-transition:leave="transition ease-in duration-150"
                            x-transition:leave-start="opacity-100 transform scale-100"
                            x-transition:leave-end="opacity-0 transform scale-95"
                            class="absolute right-0 z-50 mt-2 flex max-h-[500px] w-80 flex-col rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:border-slate-600 dark:bg-slate-800"
                            style="display: none;"
                        >
                            <!-- Header -->
                            <div class="flex items-center justify-between border-b border-gray-200 p-4 dark:border-slate-600">
                                <h3 class="font-bold text-gray-900 dark:text-white">Notifications</h3>
                                <button 
                                    x-show="unreadCount > 0"
                                    @click="markAllRead()"
                                    class="text-xs font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300"
                                >
                                    Mark all read
                                </button>
                            </div>
                            
                            <!-- Notifications List -->
                            <div class="overflow-y-auto flex-1">
                                <div x-show="loading" class="p-8 text-center">
                                    <div class="inline-block animate-spin w-6 h-6 border-4 border-indigo-500 border-t-transparent rounded-full"></div>
                                    <p class="mt-2 text-sm text-gray-500 dark:text-slate-400">Loading...</p>
                                </div>
                                
                                <div x-show="!loading && notifications.length === 0" class="p-8 text-center">
                                    <p class="text-sm text-gray-500 dark:text-slate-400">No notifications</p>
                                </div>
                                
                                <template x-for="notification in notifications" :key="notification.id">
                                    <div 
                                        :class="notification.is_read ? 'bg-white dark:bg-slate-800' : 'bg-indigo-50 dark:bg-indigo-950/40'"
                                        class="cursor-pointer border-b border-gray-100 p-4 transition-colors hover:bg-gray-50 dark:border-slate-700 dark:hover:bg-slate-700/50"
                                        @click="markAsRead(notification.id); if (notification.link) window.location.href = notification.link"
                                    >
                                        <div class="flex items-start gap-3">
                                            <div class="flex-shrink-0 mt-1">
                                                <div 
                                                    :class="{
                                                        'bg-indigo-100 text-indigo-600': notification.type === 'event_reminder',
                                                        'bg-green-100 text-green-600': notification.type === 'new_rsvp',
                                                        'bg-red-100 text-red-600': notification.type === 'event_cancelled',
                                                        'bg-purple-100 text-purple-600': notification.type === 'member_added',
                                                        'bg-gray-100 text-gray-600': ['system', 'info'].includes(notification.type)
                                                    }"
                                                    class="w-8 h-8 rounded-full flex items-center justify-center"
                                                >
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                </div>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-semibold text-gray-900 dark:text-white" x-text="notification.title"></p>
                                                <p class="mt-1 text-xs text-gray-600 dark:text-slate-300" x-text="notification.message"></p>
                                                <p class="mt-1 text-xs text-gray-400 dark:text-slate-500" x-text="formatTime(notification.created_at)"></p>
                                            </div>
                                            <div x-show="!notification.is_read" class="flex-shrink-0">
                                                <div class="w-2 h-2 bg-indigo-500 rounded-full"></div>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            
                            <!-- Footer -->
                            <div class="border-t border-gray-200 p-3 text-center dark:border-slate-600">
                                <a href="<?= e($navUrls['notifications'] ?? $adminBase . '/?page=notifications') ?>" class="text-xs font-medium text-indigo-600 hover:text-indigo-700 dark:text-indigo-400 dark:hover:text-indigo-300">
                                    View all notifications
                                </a>
                            </div>
                        </div>
                    </div>
                    
                    <script>
                        function notificationDropdown() {
                            return {
                                open: false,
                                notifications: [],
                                unreadCount: 0,
                                loading: false,
                                apiBase: '<?= e($basePath) ?>/public/api',
                                
                                init() {
                                    this.loadUnreadCount();
                                    // Poll for new notifications every 30 seconds
                                    setInterval(() => {
                                        if (!this.open) {
                                            this.loadUnreadCount();
                                        }
                                    }, 30000);
                                },
                                
                                async loadUnreadCount() {
                                    try {
                                        const response = await fetch(this.apiBase + '/notifications.php?action=list&unread_only=1&limit=1');
                                        const data = await response.json();
                                        if (data.success) {
                                            this.unreadCount = data.unread_count || 0;
                                        }
                                    } catch (error) {
                                        console.error('Error loading unread count:', error);
                                    }
                                },
                                
                                async loadNotifications() {
                                    this.loading = true;
                                    try {
                                        const response = await fetch(this.apiBase + '/notifications.php?action=list&limit=20');
                                        const data = await response.json();
                                        if (data.success) {
                                            this.notifications = data.notifications || [];
                                            this.unreadCount = data.unread_count || 0;
                                        }
                                    } catch (error) {
                                        console.error('Error loading notifications:', error);
                                    } finally {
                                        this.loading = false;
                                    }
                                },
                                
                                async markAsRead(id) {
                                    try {
                                        const response = await fetch(this.apiBase + '/notifications.php?action=mark_read', {
                                            method: 'POST',
                                            headers: { 'Content-Type': 'application/json' },
                                            body: JSON.stringify({ id: id })
                                        });
                                        const data = await response.json();
                                        if (data.success) {
                                            const notification = this.notifications.find(n => n.id == id);
                                            if (notification) {
                                                notification.is_read = 1;
                                            }
                                            this.unreadCount = Math.max(0, this.unreadCount - 1);
                                        }
                                    } catch (error) {
                                        console.error('Error marking notification as read:', error);
                                    }
                                },
                                
                                async markAllRead() {
                                    try {
                                        const response = await fetch(this.apiBase + '/notifications.php?action=mark_all_read', {
                                            method: 'POST'
                                        });
                                        const data = await response.json();
                                        if (data.success) {
                                            this.notifications.forEach(n => n.is_read = 1);
                                            this.unreadCount = 0;
                                        }
                                    } catch (error) {
                                        console.error('Error marking all as read:', error);
                                    }
                                },
                                
                                formatTime(dateString) {
                                    const date = new Date(dateString);
                                    const now = new Date();
                                    const diff = now - date;
                                    const seconds = Math.floor(diff / 1000);
                                    const minutes = Math.floor(seconds / 60);
                                    const hours = Math.floor(minutes / 60);
                                    const days = Math.floor(hours / 24);
                                    
                                    if (days > 0) return days + ' day' + (days > 1 ? 's' : '') + ' ago';
                                    if (hours > 0) return hours + ' hour' + (hours > 1 ? 's' : '') + ' ago';
                                    if (minutes > 0) return minutes + ' minute' + (minutes > 1 ? 's' : '') + ' ago';
                                    return 'Just now';
                                }
                            }
                        }
                    </script>
                    
                    <div class="h-8 w-px bg-gray-200 dark:bg-slate-600"></div>
                    
                    <div class="group flex cursor-pointer items-center space-x-3">
                        <div class="hidden text-right sm:block">
                            <div class="flex items-center justify-end gap-2">
                                <span class="text-sm font-semibold leading-none text-gray-900 dark:text-white"><?= e($user['name']) ?></span>
                                <?php if ($isCoordinator): ?>
                                <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Coordinator</span>
                                <?php else: ?>
                                <span class="rounded bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-200">Admin</span>
                                <?php endif; ?>
                            </div>
                            <div class="mt-1 text-xs text-gray-500 dark:text-slate-400"><?= e($user['email']) ?></div>
                        </div>
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-100 transition-colors group-hover:bg-indigo-200 dark:bg-indigo-900/50 dark:group-hover:bg-indigo-800/60">
                            <span class="font-bold text-indigo-600 dark:text-indigo-300"><?= strtoupper(substr($user['name'], 0, 1)) ?></span>
                        </div>
                    </div>
                </div>
            </header>

            <!-- Scrollable content -->
                <?php
                if (!isset($adminMainFullWidth) && isset($currentPage) && in_array($currentPage, ['event-create', 'event-edit'], true)) {
                    $adminMainFullWidth = true;
                }
                $rawPage = $_GET['page'] ?? '';
                if (empty($adminMainFullWidth) && in_array($rawPage, ['event-create', 'event-edit', 'facility-edit', 'facility-details'], true)) {
                    $adminMainFullWidth = true;
                }
                $adminMainTagClass = 'flex-1 min-h-0 overflow-y-auto overflow-x-hidden';
                if (!empty($adminMainFullWidth)) {
                    $adminMainTagClass .= ' admin-main-padded';
                }
                $adminMainInnerClass = !empty($adminMainFullWidth)
                    ? 'admin-main-full-width w-full min-w-0'
                    : 'mx-auto w-full max-w-screen-2xl px-4 py-5 md:px-6 md:py-6';
                $adminMainInnerStyle = !empty($adminMainFullWidth) ? ' style="width:100%;max-width:100%;margin:0;padding:0"' : '';
                ?>
            <main class="<?= e($adminMainTagClass) ?>">
                <div class="<?= e($adminMainInnerClass) ?>"<?= $adminMainInnerStyle ?>>
                
                <!-- Flash Messages -->
                <?php $flash = getFlash(); ?>
                <?php if ($flash): ?>
                    <div class="mb-6" x-data="{ show: true }" x-show="show" x-transition>
                        <div class="ta-alert <?= $flash['type'] === 'success' ? 'ta-alert-success' : 'ta-alert-error' ?> items-center justify-between">
                            <div class="flex items-center">
                                <?php if ($flash['type'] === 'success'): ?>
                                    <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <?php else: ?>
                                    <svg class="w-5 h-5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                <?php endif; ?>
                                <span class="text-sm font-medium"><?= e($flash['message']) ?></span>
                            </div>
                            <button @click="show = false" class="btn-ghost ml-auto flex-shrink-0">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
