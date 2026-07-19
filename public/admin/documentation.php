<?php

/**
 * Admin Documentation — in-app help center for staff workflows.
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Middleware\AuthMiddleware;

AuthMiddleware::requireAdminOrCoordinator();

$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();
$db = Database::getInstance();

$userData = $db->queryOne('SELECT first_name, last_name, email FROM users WHERE id = :id', ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
] : [
    'name' => 'Administrator',
    'email' => 'admin@headcount.local',
];

if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}

$pageTitle = 'Documentation';
$currentPage = 'documentation';

$docSections = [
    [
        'id' => 'getting-started',
        'title' => 'Getting started',
        'file' => '00-getting-started.php',
        'keywords' => 'login password dashboard roles admin coordinator permissions navigation sidebar',
    ],
    [
        'id' => 'events',
        'title' => 'Creating & managing events',
        'file' => '01-events.php',
        'keywords' => 'create event wizard publish draft recurrence tickets pricing capacity invite-only prayer calendar duplicate',
    ],
    [
        'id' => 'event-operations',
        'title' => 'Event day-to-day operations',
        'file' => '02-event-operations.php',
        'keywords' => 'rsvp invites share qr announcements cash payment feedback potluck corrections walk-in export',
    ],
    [
        'id' => 'checkin',
        'title' => 'Check-in',
        'file' => '03-checkin.php',
        'keywords' => 'check-in checkin qr scan offline guests undo attendance roster',
    ],
    [
        'id' => 'programs',
        'title' => 'Programs',
        'file' => '04-programs.php',
        'keywords' => 'program class session enrollment attendance weeks pricing coupons presenters sponsored',
    ],
    [
        'id' => 'facilities',
        'title' => 'Facilities & bookings',
        'file' => '05-facilities.php',
        'keywords' => 'facility room booking approve reject hours pricing managers calendar hold capture',
    ],
    [
        'id' => 'members',
        'title' => 'Members',
        'file' => '06-members.php',
        'keywords' => 'member add import csv tags groups family portal credentials',
    ],
    [
        'id' => 'reports',
        'title' => 'Reports & analytics',
        'file' => '07-reports.php',
        'keywords' => 'reports analytics export csv pdf attendance revenue no-show feedback filters',
    ],
    [
        'id' => 'payments',
        'title' => 'Payments & refunds',
        'file' => '08-payments.php',
        'keywords' => 'payments stripe refund reconcile cash pending transfer',
    ],
    [
        'id' => 'email',
        'title' => 'Email & campaigns',
        'file' => '09-email.php',
        'keywords' => 'email templates campaigns reminders merge tags audience schedule smtp',
    ],
    [
        'id' => 'settings',
        'title' => 'Settings & integrations',
        'file' => '10-settings.php',
        'keywords' => 'settings organization stripe smtp2go team permissions kiosk wordpress api waiver backup',
    ],
    [
        'id' => 'portal-kiosk',
        'title' => 'Member portal & kiosk',
        'file' => '11-portal-kiosk.php',
        'keywords' => 'portal guest member qr kiosk lobby display slideshow',
    ],
    [
        'id' => 'troubleshooting',
        'title' => 'Troubleshooting',
        'file' => '12-troubleshooting.php',
        'keywords' => 'troubleshoot help fix rsvp payment email offline permissions webhook',
    ],
    [
        'id' => 'glossary',
        'title' => 'Glossary',
        'file' => '13-glossary.php',
        'keywords' => 'glossary definitions rsvp series ticket program hold capture',
    ],
];

require __DIR__ . '/includes/header.php';

$pageHeaderTitle = 'Documentation';
$pageHeaderSubtitle = 'How to use Headcount — step-by-step guides for every admin workflow';
$pageHeaderBreadcrumb = [
    ['label' => 'Admin', 'url' => $navUrls['dashboard'] ?? ($adminBase . '/?page=dashboard')],
    ['label' => 'Documentation', 'url' => ''],
];
require __DIR__ . '/components/page-header.php';

$tocJson = [];
foreach ($docSections as $sec) {
    $tocJson[] = [
        'id' => $sec['id'],
        'title' => $sec['title'],
        'keywords' => $sec['keywords'],
    ];
}
?>

<style>
  /* Explicit layout — do not rely on Tailwind JIT for this page's grid */
  .doc-help { width: 100%; max-width: 100%; }
  .doc-layout {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
    width: 100%;
    align-items: stretch;
  }
  .doc-toc-nav {
    width: 100%;
    min-width: 0;
    max-width: 100%;
  }
  .doc-toc-list {
    display: flex;
    flex-direction: row;
    flex-wrap: nowrap;
    gap: 0.5rem;
    overflow-x: auto;
    padding: 0 0 0.5rem;
    margin: 0;
    list-style: none;
  }
  .doc-toc-list > li { flex: 0 0 auto; }
  .doc-toc-link {
    display: block;
    white-space: nowrap;
    border-radius: 0.5rem;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    line-height: 1.25rem;
    color: #4b5563;
    text-decoration: none;
    transition: background-color 0.15s, color 0.15s;
  }
  .doc-toc-link:hover { background: #f9fafb; color: #111827; }
  .doc-toc-link.is-active {
    background: #eff6ff;
    color: #1d4ed8;
    font-weight: 600;
  }
  .dark .doc-toc-link { color: #9ca3af; }
  .dark .doc-toc-link:hover { background: rgba(31, 41, 55, 0.6); color: #e5e7eb; }
  .dark .doc-toc-link.is-active {
    background: rgba(23, 37, 84, 0.4);
    color: #93c5fd;
  }
  .doc-prose {
    width: 100%;
    min-width: 0;
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
  }
  @media (min-width: 1024px) {
    .doc-layout {
      flex-direction: row;
      align-items: flex-start;
      gap: 2rem;
    }
    .doc-toc-nav {
      position: sticky;
      top: 6rem;
      width: 14rem;
      flex: 0 0 14rem;
      max-width: 14rem;
      overflow: hidden;
    }
    .doc-toc-list {
      flex-direction: column;
      flex-wrap: nowrap;
      gap: 0.125rem;
      overflow: visible;
      padding-bottom: 0;
    }
    .doc-toc-list > li { width: 100%; }
    .doc-toc-link {
      white-space: normal;
      width: 100%;
    }
    .doc-prose {
      flex: 1 1 0;
      min-width: 0;
      max-width: 100%;
    }
  }
  .doc-section-card {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
  }
  .doc-prose h2 { scroll-margin-top: 6rem; }
  .doc-prose h3 { scroll-margin-top: 5.5rem; }
  .doc-prose ol { list-style: decimal; padding-left: 1.25rem; }
  .doc-prose ul { list-style: disc; padding-left: 1.25rem; }
  .doc-prose li { margin-top: 0.35rem; margin-bottom: 0.35rem; }
  .doc-prose p { margin-top: 0.5rem; margin-bottom: 0.5rem; }
  .doc-callout {
    border-radius: 0.75rem;
    border-left-width: 4px;
    border-left-style: solid;
    padding: 0.875rem 1rem;
    margin: 1rem 0;
    font-size: 0.875rem;
    line-height: 1.5;
  }
  .doc-callout-info {
    border-left-color: #3b82f6;
    background: rgba(59, 130, 246, 0.08);
  }
  .doc-callout-warn {
    border-left-color: #f59e0b;
    background: rgba(245, 158, 11, 0.1);
  }
  .doc-callout-tip {
    border-left-color: #10b981;
    background: rgba(16, 185, 129, 0.08);
  }
  .dark .doc-callout-info { background: rgba(59, 130, 246, 0.15); }
  .dark .doc-callout-warn { background: rgba(245, 158, 11, 0.15); }
  .dark .doc-callout-tip { background: rgba(16, 185, 129, 0.15); }
  .doc-goto {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
    margin-top: 0.75rem;
    margin-right: 0.5rem;
    font-size: 0.8125rem;
    font-weight: 600;
  }
  @media print {
    .admin-sidebar, .page-header__actions, .doc-search-bar, .doc-toc-nav, .doc-goto { display: none !important; }
    .main-content { margin: 0 !important; }
    .doc-section-card { break-inside: avoid; box-shadow: none !important; border: 1px solid #ddd; }
  }
</style>

<div
  x-data="docHelp(<?= htmlspecialchars(json_encode($tocJson, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8') ?>)"
  class="doc-help"
>
  <div class="doc-search-bar mb-6">
    <label for="doc-search" class="sr-only">Search documentation</label>
    <div class="relative max-w-xl">
      <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/>
      </svg>
      <input
        id="doc-search"
        type="search"
        x-model="query"
        @input="filterSections()"
        placeholder="Search guides (e.g. create event, refunds, check-in)…"
        class="w-full rounded-xl border border-gray-200 bg-white py-2.5 pl-10 pr-4 text-sm text-gray-800 shadow-sm placeholder:text-gray-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder:text-gray-500"
      >
    </div>
    <p x-show="query.trim()" x-cloak class="mt-2 text-xs text-gray-500 dark:text-gray-400">
      Showing <span x-text="visibleCount"></span> of <?= count($docSections) ?> sections
    </p>
  </div>

  <div class="doc-layout">
    <nav class="doc-toc-nav" aria-label="Documentation contents">
      <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:text-gray-400">Contents</p>
      <ul class="doc-toc-list">
        <template x-for="sec in sections" :key="sec.id">
          <li x-show="sec.visible">
            <a
              :href="'#' + sec.id"
              @click="activeId = sec.id"
              class="doc-toc-link"
              :class="activeId === sec.id ? 'is-active' : ''"
              x-text="sec.title"
            ></a>
          </li>
        </template>
      </ul>
    </nav>

    <div class="doc-prose">
      <?php foreach ($docSections as $sec): ?>
        <section
          id="<?= e($sec['id']) ?>"
          class="doc-section-card rounded-2xl border border-gray-200 bg-white p-5 shadow-sm sm:p-7 dark:border-gray-800 dark:bg-gray-900"
          x-show="isVisible('<?= e($sec['id']) ?>')"
          data-doc-id="<?= e($sec['id']) ?>"
        >
          <?php
          $docNav = $navUrls ?? [];
          $docAdminBase = $adminBase;
          require __DIR__ . '/includes/docs/' . $sec['file'];
          ?>
        </section>
      <?php endforeach; ?>

      <p
        x-show="visibleCount === 0"
        x-cloak
        class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 px-6 py-10 text-center text-sm text-gray-500 dark:border-gray-700 dark:bg-gray-900/50 dark:text-gray-400"
      >
        No sections match your search. Try “events”, “programs”, “refunds”, or “check-in”.
      </p>
    </div>
  </div>
</div>

<script>
function docHelp(sections) {
  return {
    query: '',
    activeId: sections[0] ? sections[0].id : '',
    sections: sections.map(function (s) {
      return Object.assign({}, s, { visible: true });
    }),
    visibleCount: sections.length,
    isVisible: function (id) {
      var found = this.sections.find(function (s) { return s.id === id; });
      return found ? found.visible : true;
    },
    filterSections: function () {
      var q = (this.query || '').trim().toLowerCase();
      var count = 0;
      this.sections.forEach(function (s) {
        if (!q) {
          s.visible = true;
        } else {
          var hay = (s.title + ' ' + (s.keywords || '')).toLowerCase();
          s.visible = hay.indexOf(q) !== -1;
        }
        if (s.visible) count++;
      });
      this.visibleCount = count;
    },
    init: function () {
      var self = this;
      if (window.location.hash) {
        var hash = window.location.hash.replace('#', '');
        if (this.sections.some(function (s) { return s.id === hash; })) {
          this.activeId = hash;
        }
      }
      var observer = null;
      if ('IntersectionObserver' in window) {
        observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              self.activeId = entry.target.id;
            }
          });
        }, { rootMargin: '-20% 0px -60% 0px', threshold: 0 });
        this.$nextTick(function () {
          document.querySelectorAll('.doc-section-card').forEach(function (el) {
            observer.observe(el);
          });
        });
      }
    }
  };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
