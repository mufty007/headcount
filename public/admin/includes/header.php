<!DOCTYPE html>
<html lang="en" class="h-full">
<?php
if (!function_exists('e')) {
    require_once __DIR__ . '/../../../src/helpers.php';
}
require_once __DIR__ . '/layout-vars.php';

if (!isset($currentPage)) {
    $currentPage = $_GET['page'] ?? 'dashboard';
}

if (!isset($user)) {
    $user = [
        'name'  => $_SESSION['name'] ?? trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) ?: 'Administrator',
        'email' => $_SESSION['email'] ?? 'admin@headcount.local',
        'role'  => $_SESSION['role'] ?? 'admin',
    ];
}
if (!isset($user['role'])) {
    $user['role'] = $_SESSION['role'] ?? 'admin';
}
$isCoordinator = (isset($user['role']) && $user['role'] === 'coordinator');

if (!isset($APP_NAME)) { $APP_NAME = 'Headcount'; }

/* Determine which sidebar group is open by default based on $currentPage */
$sidebarSelected = '';
if (in_array($currentPage, ['events', 'event-create', 'event-edit', 'event-details'], true)) {
    $sidebarSelected = 'Events';
} elseif (in_array($currentPage, ['programs', 'program-edit', 'program-details', 'program-attendance'], true)) {
    $sidebarSelected = 'Programs';
} elseif (in_array($currentPage, ['facilities', 'facility-edit', 'facility-details', 'facility-bookings'], true)) {
    $sidebarSelected = 'Facilities';
}
?>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    (function(){var K='headcount-admin-theme';var t=null;try{t=localStorage.getItem(K);}catch(e){}
    var d=t==='dark'||(t!=='light'&&typeof matchMedia!=='undefined'&&matchMedia('(prefers-color-scheme:dark)').matches);
    document.documentElement.classList.toggle('dark',!!d);})();
    </script>
    <style>[x-cloak]{display:none!important}</style>
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> - <?= htmlspecialchars($APP_NAME) ?></title>

    <!-- Alpine.js -->
    <script src="https://unpkg.com/alpinejs@3.13.5/dist/cdn.min.js" defer></script>

    <!-- Admin JS (navigation, confirm, etc.) -->
    <?php
    if (!function_exists('buildJsPathHdr')) {
        function buildJsPathHdr($basePath, $filename) {
            if (empty($basePath) || $basePath === '/') {
                $p = '/public/js/' . $filename;
            } else {
                $bp = ($basePath[0] !== '/') ? '/' . $basePath : $basePath;
                $p  = rtrim($bp, '/') . '/public/js/' . $filename;
            }
            $p = preg_replace('#/+#', '/', $p);
            if ($p[0] !== '/') $p = '/' . $p;
            return $p;
        }
    }
    echo '<script src="' . e(buildJsPathHdr($basePath, 'admin-app.js')) . '" defer></script>' . "\n    ";
    ?>

    <!-- CSS -->
    <?php
    $cssDir = realpath(__DIR__ . '/../../css');
    $docRoot = !empty($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
    $cssWebBase = null;
    if ($cssDir && $docRoot) {
        $cn = str_replace('\\', '/', $cssDir);
        $rn = str_replace('\\', '/', $docRoot);
        if (strpos($cn, $rn) === 0) {
            $cssWebBase = '/' . trim(substr($cn, strlen($rn)), '/');
        }
    }
    if (!$cssWebBase) {
        $cssWebBase = empty($basePath) || $basePath === '/'
            ? '/public/css'
            : '/' . trim(preg_replace('#/+#', '/', $basePath . '/public/css'), '/');
    }
    $cssWebBase = '/' . ltrim(preg_replace('#/+#', '/', $cssWebBase), '/');

    function hcCssUrl($dir, $webBase, $file) {
        $fs = $dir . DIRECTORY_SEPARATOR . $file;
        $v  = is_file($fs) ? (int)@filemtime($fs) : 0;
        return $webBase . '/' . $file . ($v ? '?v=' . $v : '');
    }

    $twHref    = hcCssUrl($cssDir, $cssWebBase, 'tailwind-output.css');
    $modalHref = hcCssUrl($cssDir, $cssWebBase, 'modal.css');

    if ($cssDir && is_file($cssDir . DIRECTORY_SEPARATOR . 'tailwind-output.css')) {
        echo '<link rel="stylesheet" href="' . e($twHref) . '">' . "\n    ";
    } else {
        echo '<script src="https://cdn.tailwindcss.com"></script>' . "\n    ";
    }
    echo '<link rel="stylesheet" href="' . e($modalHref) . '">' . "\n    ";

    if (!empty($adminMainFullWidth)) {
        echo '<style id="admin-full-width-layout">'
            . 'body.admin-layout-full-width{padding:0!important}'
            . 'body.admin-layout-full-width .main-content>main{width:100%!important;max-width:none!important}'
            . '</style>' . "\n    ";
    }

    if (!empty($requiresEventWizard)) {
        if (!function_exists('headcount_admin_js_emit')) {
            require_once __DIR__ . '/../../../src/helpers.php';
        }
        headcount_admin_js_emit('event-wizard-steps.js?v=8');
        headcount_admin_js_emit('event-pricing-tabs.js?v=5');
    }

    /* Quill */
    if (!function_exists('headcountQuillHasLocalAssets')) {
        function headcountQuillHasLocalAssets(): bool {
            foreach ([__DIR__ . '/../vendor/quill/quill.js', __DIR__ . '/../../js/vendor/quill/quill.js'] as $p) {
                if (is_file($p)) return true;
            }
            $base = defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__, 3);
            return is_file($base . '/node_modules/quill/dist/quill.js') || is_file($base . '/node_modules/quill/dist/quill.min.js');
        }
    }
    if (!function_exists('headcountQuillAssetUrl')) {
        function headcountQuillAssetUrl($filename) {
            global $adminBase;
            $map = ['quill.js' => 'quill.js', 'quill.snow.css' => 'quill.snow.css'];
            $file = $map[ltrim((string)$filename, '/')] ?? '';
            if ($file === '') return '';
            return rtrim((string)$adminBase, '/') . '/?page=quill-asset&f=' . $file;
        }
    }
    $needsQuill = !empty($requiresQuillEditor);
    if (!$needsQuill && !empty($additionalJS) && is_array($additionalJS)) {
        foreach ($additionalJS as $j) { if (is_string($j) && stripos($j, 'quill') !== false) { $needsQuill = true; break; } }
    }
    if ($needsQuill) {
        if (headcountQuillHasLocalAssets()) {
            $qv = is_file(__DIR__ . '/../vendor/quill/quill.js') ? (int)@filemtime(__DIR__ . '/../vendor/quill/quill.js') : 0;
            $qcss = headcountQuillAssetUrl('quill.snow.css') . ($qv ? '&v=' . $qv : '');
            $qjs  = headcountQuillAssetUrl('quill.js')      . ($qv ? '&v=' . $qv : '');
        } else {
            $qcss = 'https://cdn.quilljs.com/1.3.6/quill.snow.css';
            $qjs  = 'https://cdn.quilljs.com/1.3.6/quill.js';
        }
        echo '<link rel="stylesheet" href="' . e($qcss) . '">' . "\n    ";
        echo '<script src="' . e($qjs) . '"></script>' . "\n    ";
    }

    if (isset($additionalCSS) && is_array($additionalCSS)) {
        foreach ($additionalCSS as $css) {
            if (is_string($css) && stripos($css, 'quill') !== false) continue;
            echo '<link rel="stylesheet" href="' . htmlspecialchars($css) . '">' . "\n    ";
        }
    }
    ?>

    <script>
    window.__quillInstances = window.__quillInstances || new Map();
    window.initWYSIWYG = function(sel, opts) {
        opts = opts || {};
        if (typeof Quill === 'undefined') return;
        document.querySelectorAll(sel).forEach(function(el) {
            if (el.dataset.quillInitialized || window.__quillInstances.has(el)) return;
            el.dataset.quillInitialized = 'true';
            el.style.display = 'none';
            var container = document.createElement('div');
            el.parentNode.insertBefore(container, el.nextSibling);
            var quill;
            try {
                quill = new Quill(container, {
                    theme: 'snow',
                    placeholder: el.getAttribute('placeholder') || 'Type here...',
                    modules: { toolbar: [['bold','italic','underline','strike'],[{list:'ordered'},{list:'bullet'}],['link','clean']] }
                });
            } catch(err) { el.style.display = ''; container.remove(); delete el.dataset.quillInitialized; return; }
            window.__quillInstances.set(el, quill);
            quill.root.innerHTML = el.value || '';
            quill.on('text-change', function() {
                var html = quill.root.innerHTML;
                el.value = (html === '<p><br></p>') ? '' : html;
                if (opts.onChange) opts.onChange(el.value);
                el.dispatchEvent(new Event('input', {bubbles:true}));
                el.dispatchEvent(new Event('change', {bubbles:true}));
            });
            el.addEventListener('sync-to-quill', function() {
                if (quill.root.innerHTML !== el.value) quill.root.innerHTML = el.value || '';
            });
        });
    };
    function headcountBootWysiwygEditors() {
        if (typeof Quill === 'undefined' || typeof window.initWYSIWYG !== 'function') return false;
        window.initWYSIWYG('.wysiwyg-editor');
        return true;
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', headcountBootWysiwygEditors);
    else headcountBootWysiwygEditors();
    window.addEventListener('load', headcountBootWysiwygEditors);
    </script>

    <script>
    document.addEventListener('alpine:init', function() {
        Alpine.data('adminShell', function() {
            return {
                sidebarToggle: false,
                themeMenuOpen: false,
                theme: 'system',
                init: function() {
                    try { this.sidebarToggle = localStorage.getItem('sidebarToggle') === 'true'; } catch(e) {}
                    try { this.theme = localStorage.getItem('headcount-admin-theme') || 'system'; } catch(e) {}
                    this.applyTheme();
                    var self = this;
                    if (typeof matchMedia !== 'undefined') {
                        matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function() {
                            if (self.theme === 'system') self.applyTheme();
                        });
                    }
                },
                isDark: function() {
                    return this.theme === 'dark' || (this.theme === 'system' && typeof matchMedia !== 'undefined' && matchMedia('(prefers-color-scheme: dark)').matches);
                },
                applyTheme: function() {
                    document.documentElement.classList.toggle('dark', this.isDark());
                    window.dispatchEvent(new CustomEvent('headcount-theme-change', { detail: { dark: this.isDark() } }));
                },
                setTheme: function(mode) {
                    this.theme = mode;
                    try { localStorage.setItem('headcount-admin-theme', mode); } catch(e) {}
                    this.applyTheme();
                    this.themeMenuOpen = false;
                },
                toggleSidebar: function() {
                    this.sidebarToggle = !this.sidebarToggle;
                    try { localStorage.setItem('sidebarToggle', this.sidebarToggle); } catch(e) {}
                }
            };
        });
    });
    </script>
</head>

<body
    class="font-outfit h-full overflow-hidden antialiased text-gray-900 dark:text-gray-100<?= !empty($adminMainFullWidth) ? ' admin-layout-full-width' : '' ?>"
    x-data="adminShell"
>

<!-- ===================== OUTER FLEX ===================== -->
<div class="app-container">

  <!-- ==================== SIDEBAR ==================== -->
  <aside
    :class="sidebarToggle ? 'translate-x-0 lg:w-[90px]' : '-translate-x-full'"
    class="fixed left-0 top-0 z-[9999] flex h-screen w-[290px] flex-col overflow-y-hidden border-r border-gray-200 bg-white px-5 duration-300 ease-linear dark:border-gray-800 dark:bg-gray-900 lg:static lg:translate-x-0"
    role="navigation"
    aria-label="Main navigation"
  >

    <!-- Sidebar Header -->
    <div
      :class="sidebarToggle ? 'justify-center' : 'justify-between'"
      class="flex items-center gap-2 py-6 border-b border-gray-200 dark:border-gray-800"
    >
      <a href="<?= e($navUrls['dashboard']) ?>" class="flex items-center gap-2 min-w-0">
        <img src="<?= e($assetsBase) ?>images/logo.svg" alt="Headcount logo" class="h-8 w-8 shrink-0 rounded-lg">
        <span
          :class="sidebarToggle ? 'lg:hidden' : ''"
          class="text-xl font-bold tracking-tight text-gray-900 dark:text-white whitespace-nowrap overflow-hidden"
        ><?= htmlspecialchars($APP_NAME) ?></span>
      </a>
      <!-- Close button (mobile only) -->
      <button
        type="button"
        @click="sidebarToggle = false"
        :class="sidebarToggle ? 'lg:hidden' : ''"
        class="flex lg:hidden items-center justify-center p-1.5 rounded-lg text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
        aria-label="Close sidebar"
      >
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
      </button>
    </div>

    <!-- Scrollable Nav -->
    <div class="no-scrollbar flex flex-col overflow-y-auto duration-300 ease-linear">
      <nav x-data="{selected: '<?= e($sidebarSelected) ?>'}" class="mt-5 lg:mt-9 px-2">

        <!-- ── MENU GROUP ── -->
        <div class="mb-6">
          <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            <span :class="sidebarToggle ? 'lg:hidden' : ''">Menu</span>
          </h3>

          <ul class="flex flex-col gap-1">

            <!-- Dashboard -->
            <li>
              <a href="<?= e($navUrls['dashboard']) ?>"
                 class="menu-item group <?= $currentPage === 'dashboard' ? 'menu-item-active' : 'menu-item-inactive' ?>">
                <svg class="w-6 h-6 shrink-0 <?= $currentPage === 'dashboard' ? 'menu-item-icon-active' : 'menu-item-icon-inactive' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Dashboard</span>
              </a>
            </li>

            <!-- Events (dropdown) -->
            <li>
              <button type="button"
                @click.prevent="selected = (selected === 'Events' ? '' : 'Events')"
                class="w-full menu-item group <?= in_array($currentPage, ['events','event-create','event-edit','event-details'], true) ? 'menu-item-active' : 'menu-item-inactive' ?>">
                <svg class="w-6 h-6 shrink-0 <?= in_array($currentPage, ['events','event-create','event-edit','event-details'], true) ? 'menu-item-icon-active' : 'menu-item-icon-inactive' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
                <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Events</span>
                <svg class="menu-item-arrow" :class="[selected==='Events' ? 'menu-item-arrow-active' : 'menu-item-arrow-inactive', sidebarToggle ? 'lg:hidden' : '']" width="20" height="20" viewBox="0 0 20 20" fill="none">
                  <path d="M4.792 7.396L10 12.604l5.208-5.208" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
              <div :class="selected === 'Events' ? 'block' : 'hidden'" class="overflow-hidden">
                <ul :class="sidebarToggle ? 'lg:hidden' : 'flex'" class="menu-dropdown mt-2 flex flex-col gap-0.5">
                  <li>
                    <a href="<?= e($navUrls['events']) ?>" class="menu-dropdown-item group <?= $currentPage === 'events' ? 'menu-dropdown-item-active' : 'menu-dropdown-item-inactive' ?>">All Events</a>
                  </li>
                  <li>
                    <a href="<?= e($navUrls['event-create'] ?? ($adminBase . '/?page=event-create')) ?>" class="menu-dropdown-item group <?= $currentPage === 'event-create' ? 'menu-dropdown-item-active' : 'menu-dropdown-item-inactive' ?>">Create Event</a>
                  </li>
                </ul>
              </div>
            </li>

            <!-- Programs (dropdown) -->
            <li>
              <button type="button"
                @click.prevent="selected = (selected === 'Programs' ? '' : 'Programs')"
                class="w-full menu-item group <?= in_array($currentPage, ['programs','program-edit','program-details','program-attendance'], true) ? 'menu-item-active' : 'menu-item-inactive' ?>">
                <svg class="w-6 h-6 shrink-0 <?= in_array($currentPage, ['programs','program-edit','program-details','program-attendance'], true) ? 'menu-item-icon-active' : 'menu-item-icon-inactive' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/>
                </svg>
                <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Programs</span>
                <svg class="menu-item-arrow" :class="[selected==='Programs' ? 'menu-item-arrow-active' : 'menu-item-arrow-inactive', sidebarToggle ? 'lg:hidden' : '']" width="20" height="20" viewBox="0 0 20 20" fill="none">
                  <path d="M4.792 7.396L10 12.604l5.208-5.208" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
              <div :class="selected === 'Programs' ? 'block' : 'hidden'" class="overflow-hidden">
                <ul :class="sidebarToggle ? 'lg:hidden' : 'flex'" class="menu-dropdown mt-2 flex flex-col gap-0.5">
                  <li>
                    <a href="<?= e($navUrls['programs']) ?>" class="menu-dropdown-item group <?= in_array($currentPage, ['programs','program-edit','program-details'], true) ? 'menu-dropdown-item-active' : 'menu-dropdown-item-inactive' ?>">All Programs</a>
                  </li>
                  <li>
                    <a href="<?= e($navUrls['program-attendance']) ?>" class="menu-dropdown-item group <?= $currentPage === 'program-attendance' ? 'menu-dropdown-item-active' : 'menu-dropdown-item-inactive' ?>">Attendance</a>
                  </li>
                </ul>
              </div>
            </li>

            <!-- Facilities (dropdown) -->
            <li>
              <button type="button"
                @click.prevent="selected = (selected === 'Facilities' ? '' : 'Facilities')"
                class="w-full menu-item group <?= in_array($currentPage, ['facilities','facility-edit','facility-details','facility-bookings'], true) ? 'menu-item-active' : 'menu-item-inactive' ?>">
                <svg class="w-6 h-6 shrink-0 <?= in_array($currentPage, ['facilities','facility-edit','facility-details','facility-bookings'], true) ? 'menu-item-icon-active' : 'menu-item-icon-inactive' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                </svg>
                <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Facilities</span>
                <svg class="menu-item-arrow" :class="[selected==='Facilities' ? 'menu-item-arrow-active' : 'menu-item-arrow-inactive', sidebarToggle ? 'lg:hidden' : '']" width="20" height="20" viewBox="0 0 20 20" fill="none">
                  <path d="M4.792 7.396L10 12.604l5.208-5.208" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
              </button>
              <div :class="selected === 'Facilities' ? 'block' : 'hidden'" class="overflow-hidden">
                <ul :class="sidebarToggle ? 'lg:hidden' : 'flex'" class="menu-dropdown mt-2 flex flex-col gap-0.5">
                  <li>
                    <a href="<?= e($navUrls['facilities'] ?? ($adminBase . '/?page=facilities')) ?>" class="menu-dropdown-item group <?= in_array($currentPage, ['facilities','facility-edit','facility-details'], true) ? 'menu-dropdown-item-active' : 'menu-dropdown-item-inactive' ?>">All Facilities</a>
                  </li>
                  <li>
                    <a href="<?= e($navUrls['facility-bookings'] ?? ($adminBase . '/?page=facility-bookings')) ?>" class="menu-dropdown-item group <?= $currentPage === 'facility-bookings' ? 'menu-dropdown-item-active' : 'menu-dropdown-item-inactive' ?>">Bookings</a>
                  </li>
                </ul>
              </div>
            </li>

            <?php if (!$isCoordinator): ?>
            <!-- Members -->
            <li>
              <a href="<?= e($navUrls['members']) ?>"
                 class="menu-item group <?= $currentPage === 'members' ? 'menu-item-active' : 'menu-item-inactive' ?>">
                <svg class="w-6 h-6 shrink-0 <?= $currentPage === 'members' ? 'menu-item-icon-active' : 'menu-item-icon-inactive' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Members</span>
              </a>
            </li>
            <?php endif; ?>

            <!-- Check-In -->
            <li>
              <a href="<?= e($navUrls['checkin']) ?>"
                 class="menu-item group <?= $currentPage === 'checkin' ? 'menu-item-active' : 'menu-item-inactive' ?>">
                <svg class="w-6 h-6 shrink-0 <?= $currentPage === 'checkin' ? 'menu-item-icon-active' : 'menu-item-icon-inactive' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Check-In</span>
              </a>
            </li>

          </ul>
        </div>

        <!-- ── REPORTS & FINANCE GROUP ── -->
        <div class="mb-6">
          <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            <span :class="sidebarToggle ? 'lg:hidden' : ''">Reports & Finance</span>
          </h3>
          <ul class="flex flex-col gap-1">

            <li>
              <a href="<?= e($navUrls['reports']) ?>"
                 class="menu-item group <?= $currentPage === 'reports' ? 'menu-item-active' : 'menu-item-inactive' ?>">
                <svg class="w-6 h-6 shrink-0 <?= $currentPage === 'reports' ? 'menu-item-icon-active' : 'menu-item-icon-inactive' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Reports</span>
              </a>
            </li>

            <?php if (!$isCoordinator): ?>
            <li>
              <a href="<?= e($navUrls['payment-transfers']) ?>"
                 class="menu-item group <?= $currentPage === 'payment-transfers' ? 'menu-item-active' : 'menu-item-inactive' ?>">
                <svg class="w-6 h-6 shrink-0 <?= $currentPage === 'payment-transfers' ? 'menu-item-icon-active' : 'menu-item-icon-inactive' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Payments</span>
              </a>
            </li>
            <li>
              <a href="<?= e($navUrls['refund-requests']) ?>"
                 class="menu-item group <?= $currentPage === 'refund-requests' ? 'menu-item-active' : 'menu-item-inactive' ?>">
                <svg class="w-6 h-6 shrink-0 <?= $currentPage === 'refund-requests' ? 'menu-item-icon-active' : 'menu-item-icon-inactive' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                </svg>
                <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Refund Requests</span>
              </a>
            </li>
            <?php endif; ?>

          </ul>
        </div>

        <!-- ── SYSTEM GROUP (admin only) ── -->
        <?php if (!$isCoordinator): ?>
        <div class="mb-6">
          <h3 class="mb-4 text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            <span :class="sidebarToggle ? 'lg:hidden' : ''">System</span>
          </h3>
          <ul class="flex flex-col gap-1">

            <li>
              <a href="<?= e($navUrls['notifications']) ?>"
                 class="menu-item group <?= $currentPage === 'notifications' ? 'menu-item-active' : 'menu-item-inactive' ?>">
                <svg class="w-6 h-6 shrink-0 <?= $currentPage === 'notifications' ? 'menu-item-icon-active' : 'menu-item-icon-inactive' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
                <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Notifications</span>
              </a>
            </li>

            <li>
              <a href="<?= e($navUrls['activity-log']) ?>"
                 class="menu-item group <?= $currentPage === 'activity-log' ? 'menu-item-active' : 'menu-item-inactive' ?>">
                <svg class="w-6 h-6 shrink-0 <?= $currentPage === 'activity-log' ? 'menu-item-icon-active' : 'menu-item-icon-inactive' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Activity Log</span>
              </a>
            </li>

            <li>
              <a href="<?= e($navUrls['email-templates'] ?? ($adminBase . '/?page=email-templates')) ?>"
                 class="menu-item group <?= $currentPage === 'email-templates' ? 'menu-item-active' : 'menu-item-inactive' ?>">
                <svg class="w-6 h-6 shrink-0 <?= $currentPage === 'email-templates' ? 'menu-item-icon-active' : 'menu-item-icon-inactive' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                </svg>
                <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Email Templates</span>
              </a>
            </li>

            <li>
              <a href="<?= e($navUrls['campaigns']) ?>"
                 class="menu-item group <?= $currentPage === 'email-campaigns' ? 'menu-item-active' : 'menu-item-inactive' ?>">
                <svg class="w-6 h-6 shrink-0 <?= $currentPage === 'email-campaigns' ? 'menu-item-icon-active' : 'menu-item-icon-inactive' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"/>
                </svg>
                <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Campaigns</span>
              </a>
            </li>

            <li>
              <a href="<?= e($navUrls['settings']) ?>"
                 class="menu-item group <?= $currentPage === 'settings' ? 'menu-item-active' : 'menu-item-inactive' ?>">
                <svg class="w-6 h-6 shrink-0 <?= $currentPage === 'settings' ? 'menu-item-icon-active' : 'menu-item-icon-inactive' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Settings</span>
              </a>
            </li>

          </ul>
        </div>
        <?php endif; ?>

        <!-- Sign Out -->
        <div class="mt-auto border-t border-gray-200 pt-4 pb-2 dark:border-gray-800">
          <a href="<?= e($adminBase . '/?page=logout') ?>"
             class="menu-item group menu-item-inactive text-red-600 hover:text-red-700 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-950/40 dark:hover:text-red-300">
            <svg class="w-6 h-6 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            <span class="menu-item-text" :class="sidebarToggle ? 'lg:hidden' : ''">Sign Out</span>
          </a>
        </div>

      </nav>
    </div>
  </aside>

  <!-- ===================== MAIN CONTENT ===================== -->
  <div
    class="main-content flex h-screen min-h-0 flex-col overflow-hidden"
    :class="sidebarToggle ? 'sidebar-collapsed' : ''"
  >

    <!-- Mobile sidebar overlay -->
    <div
      x-show="!sidebarToggle"
      @click="sidebarToggle = true"
      class="fixed inset-0 z-[9998] bg-gray-900/50 backdrop-blur-sm lg:hidden"
      x-transition:enter="transition-opacity ease-out duration-300"
      x-transition:enter-start="opacity-0"
      x-transition:enter-end="opacity-100"
      x-transition:leave="transition-opacity ease-in duration-200"
      x-transition:leave-start="opacity-100"
      x-transition:leave-end="opacity-0"
      style="display:none;"
    ></div>

    <!-- ── TOP HEADER ── -->
    <header class="sticky top-0 z-[99999] flex h-16 w-full shrink-0 items-center gap-3 border-b border-gray-200 bg-white px-4 dark:border-gray-800 dark:bg-gray-900 sm:px-6">

      <!-- Hamburger / sidebar toggle -->
      <button
        type="button"
        @click="toggleSidebar()"
        :aria-expanded="!sidebarToggle"
        aria-controls="sidebar-nav"
        class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
        aria-label="Toggle sidebar"
      >
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
      </button>

      <!-- Page title (mobile) -->
      <h2 class="text-base font-semibold text-gray-800 dark:text-gray-100 lg:hidden truncate">
        <?= htmlspecialchars($pageTitle ?? $APP_NAME) ?>
      </h2>

      <!-- Search (desktop) -->
      <div class="hidden flex-1 justify-center px-4 md:flex">
        <div class="flex w-full max-w-md items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-700 dark:bg-gray-800">
          <svg class="h-4 w-4 shrink-0 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
          </svg>
          <input type="search" placeholder="Quick search…" class="w-full border-0 bg-transparent text-sm text-gray-800 placeholder-gray-400 outline-none dark:text-gray-200 dark:placeholder-gray-500" autocomplete="off">
        </div>
      </div>

      <!-- Right-side controls -->
      <div class="ml-auto flex shrink-0 items-center gap-2 sm:gap-3 md:ml-0">

        <!-- Dark mode toggle -->
        <div class="relative" @click.outside="themeMenuOpen = false">
          <button
            type="button"
            @click="themeMenuOpen = !themeMenuOpen"
            :aria-expanded="themeMenuOpen"
            aria-haspopup="true"
            aria-label="Color theme"
            class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-gray-600 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
          >
            <svg x-show="!isDark()" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
            <svg x-show="isDark()" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true" x-cloak>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
            </svg>
          </button>
          <div
            x-show="themeMenuOpen"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="absolute right-0 z-50 mt-2 w-40 rounded-xl border border-gray-200 bg-white py-1 shadow-theme-lg dark:border-gray-700 dark:bg-gray-900"
            style="display:none;"
            role="menu"
          >
            <button type="button" role="menuitem" @click="setTheme('light')" :class="{'bg-brand-50 font-semibold text-brand-600 dark:bg-brand-950/30 dark:text-brand-400': theme==='light'}" class="flex w-full items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800">Light</button>
            <button type="button" role="menuitem" @click="setTheme('dark')" :class="{'bg-brand-50 font-semibold text-brand-600 dark:bg-brand-950/30 dark:text-brand-400': theme==='dark'}" class="flex w-full items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800">Dark</button>
            <button type="button" role="menuitem" @click="setTheme('system')" :class="{'bg-brand-50 font-semibold text-brand-600 dark:bg-brand-950/30 dark:text-brand-400': theme==='system'}" class="flex w-full items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-800">System</button>
          </div>
        </div>

        <!-- Notifications -->
        <div class="relative" x-data="notificationDropdown()" @click.outside="open = false">
          <button
            type="button"
            @click="open = !open; if(open) loadNotifications()"
            :aria-expanded="open"
            aria-haspopup="true"
            aria-label="Notifications"
            @keydown.escape.window="open = false"
            class="relative flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-gray-600 transition-colors hover:bg-gray-50 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
            </svg>
            <span
              x-show="unreadCount > 0"
              x-text="unreadCount > 99 ? '99+' : unreadCount"
              class="absolute -right-1 -top-1 flex h-[18px] min-w-[18px] items-center justify-center rounded-full border-2 border-white bg-red-500 px-1 text-[10px] font-bold text-white dark:border-gray-900"
            ></span>
          </button>
          <div
            x-show="open"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="opacity-100 scale-100"
            x-transition:leave-end="opacity-0 scale-95"
            class="absolute right-0 z-50 mt-2 flex max-h-[480px] w-80 flex-col rounded-2xl border border-gray-200 bg-white shadow-theme-lg dark:border-gray-700 dark:bg-gray-900"
            style="display:none;"
          >
            <div class="flex items-center justify-between border-b border-gray-100 p-4 dark:border-gray-800">
              <h3 class="font-semibold text-gray-900 dark:text-white">Notifications</h3>
              <button x-show="unreadCount > 0" @click="markAllRead()" class="text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">Mark all read</button>
            </div>
            <div class="overflow-y-auto flex-1">
              <div x-show="loading" class="p-8 text-center">
                <div class="inline-block h-6 w-6 animate-spin rounded-full border-4 border-brand-500 border-t-transparent"></div>
              </div>
              <div x-show="!loading && notifications.length === 0" class="p-8 text-center text-sm text-gray-500 dark:text-gray-400">No notifications</div>
              <template x-for="n in notifications" :key="n.id">
                <div
                  :class="n.is_read ? '' : 'bg-brand-50/60 dark:bg-brand-950/20'"
                  class="cursor-pointer border-b border-gray-100 p-4 transition-colors hover:bg-gray-50 dark:border-gray-800 dark:hover:bg-gray-800/50"
                  @click="markAsRead(n.id); if(n.link) window.location.href = n.link"
                >
                  <div class="flex items-start gap-3">
                    <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400">
                      <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                      <p class="text-sm font-semibold text-gray-900 dark:text-white" x-text="n.title"></p>
                      <p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400" x-text="n.message"></p>
                      <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500" x-text="formatTime(n.created_at)"></p>
                    </div>
                    <div x-show="!n.is_read" class="shrink-0">
                      <div class="h-2 w-2 rounded-full bg-brand-500"></div>
                    </div>
                  </div>
                </div>
              </template>
            </div>
            <div class="border-t border-gray-100 p-3 text-center dark:border-gray-800">
              <a href="<?= e($navUrls['notifications'] ?? $adminBase . '/?page=notifications') ?>" class="text-xs font-medium text-brand-600 hover:text-brand-700 dark:text-brand-400">View all notifications</a>
            </div>
          </div>
        </div>

        <script>
        function notificationDropdown() {
            return {
                open: false, notifications: [], unreadCount: 0, loading: false,
                apiBase: '<?= e($basePath) ?>/public/api',
                init() {
                    this.loadUnreadCount();
                    setInterval(() => { if (!this.open) this.loadUnreadCount(); }, 30000);
                },
                async loadUnreadCount() {
                    try {
                        const r = await fetch(this.apiBase + '/notifications.php?action=list&unread_only=1&limit=1');
                        const d = await r.json();
                        if (d.success) this.unreadCount = d.unread_count || 0;
                    } catch(e) {}
                },
                async loadNotifications() {
                    this.loading = true;
                    try {
                        const r = await fetch(this.apiBase + '/notifications.php?action=list&limit=20');
                        const d = await r.json();
                        if (d.success) { this.notifications = d.notifications || []; this.unreadCount = d.unread_count || 0; }
                    } catch(e) {} finally { this.loading = false; }
                },
                async markAsRead(id) {
                    try {
                        const r = await fetch(this.apiBase + '/notifications.php?action=mark_read', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id})});
                        const d = await r.json();
                        if (d.success) { const n = this.notifications.find(n=>n.id==id); if(n) n.is_read=1; this.unreadCount = Math.max(0, this.unreadCount-1); }
                    } catch(e) {}
                },
                async markAllRead() {
                    try {
                        const r = await fetch(this.apiBase + '/notifications.php?action=mark_all_read', {method:'POST'});
                        const d = await r.json();
                        if (d.success) { this.notifications.forEach(n=>n.is_read=1); this.unreadCount=0; }
                    } catch(e) {}
                },
                formatTime(ds) {
                    const diff = Date.now() - new Date(ds).getTime(), s=Math.floor(diff/1000), m=Math.floor(s/60), h=Math.floor(m/60), day=Math.floor(h/24);
                    if (day > 0) return day + (day>1?' days':' day') + ' ago';
                    if (h > 0)   return h   + (h>1?' hours':' hour') + ' ago';
                    if (m > 0)   return m   + (m>1?' minutes':' minute') + ' ago';
                    return 'Just now';
                }
            };
        }
        </script>

        <!-- Divider -->
        <div class="h-8 w-px bg-gray-200 dark:bg-gray-700"></div>

        <!-- User info -->
        <div class="flex items-center gap-3">
          <div class="hidden text-right sm:block">
            <p class="text-sm font-semibold leading-none text-gray-900 dark:text-white"><?= e($user['name']) ?></p>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400"><?= e($user['email']) ?></p>
          </div>
          <div class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-100 dark:bg-brand-900/40">
            <span class="text-sm font-bold text-brand-600 dark:text-brand-400"><?= strtoupper(substr($user['name'], 0, 1)) ?></span>
          </div>
        </div>

      </div>
    </header>

    <!-- ── SCROLLABLE PAGE CONTENT ── -->
    <?php
    if (!isset($adminMainFullWidth) && isset($currentPage) && in_array($currentPage, ['event-create','event-edit'], true)) {
        $adminMainFullWidth = true;
    }
    $rawPage = $_GET['page'] ?? '';
    if (empty($adminMainFullWidth) && in_array($rawPage, ['event-create','event-edit','facility-edit','facility-details'], true)) {
        $adminMainFullWidth = true;
    }
    $mainClass = 'flex-1 min-h-0 overflow-y-auto overflow-x-hidden';
    $innerClass = !empty($adminMainFullWidth)
        ? 'w-full min-w-0'
        : 'mx-auto w-full max-w-screen-2xl px-4 py-6 md:px-6';
    ?>
    <main class="<?= e($mainClass) ?>">
      <div class="<?= e($innerClass) ?>">

        <!-- Flash messages -->
        <?php $flash = getFlash(); ?>
        <?php if ($flash): ?>
        <div class="mb-6" x-data="{show:true}" x-show="show" x-transition>
          <div class="ta-alert <?= $flash['type'] === 'success' ? 'ta-alert-success' : 'ta-alert-error' ?> items-center justify-between">
            <div class="flex items-center gap-3">
              <?php if ($flash['type'] === 'success'): ?>
                <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              <?php else: ?>
                <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
              <?php endif; ?>
              <span class="text-sm font-medium"><?= e($flash['message']) ?></span>
            </div>
            <button type="button" @click="show=false" class="btn-ghost ml-auto shrink-0">
              <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
        </div>
        <?php endif; ?>
