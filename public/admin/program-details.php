<?php

/**
 * Admin single program hub — overview, registrants, sessions/attendance, share.
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
use Headcount\Helpers\Utilities;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\ProgramService;

AuthMiddleware::requireAdminCoordinatorOrPresenter();

$organizationId = AuthMiddleware::getOrganizationId();
$config = require HC_PROJECT_ROOT . '/config/config.php';
$db = Database::getInstance($config['database']);

$userId = AuthMiddleware::getUserId();
$userData = $db->queryOne('SELECT first_name, last_name, email, role FROM users WHERE id = :id', ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
    'role' => $userData['role'] ?? 'admin',
] : ['name' => 'Admin', 'email' => '', 'role' => 'admin'];

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
$basePath = preg_replace('#/admin/.*$#', '', $requestPath);
$basePath = rtrim($basePath, '/');
$adminBase = $basePath . '/admin';

$programId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($programId <= 0) {
    Utilities::redirect($adminBase . '/?page=programs');
    exit;
}

$svc = new ProgramService();
if (!$svc->tableExists('programs')) {
    Utilities::redirect($adminBase . '/?page=programs');
    exit;
}

$program = $svc->getByIdForOrg($programId, $organizationId);
if (!$program) {
    Utilities::redirect($adminBase . '/?page=programs');
    exit;
}
if (AuthMiddleware::isPresenter() && !$svc->userIsAssignedPresenter((int) $userId, $programId, (int) $organizationId)) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

if (!empty($program['title'])) {
    $program['title'] = Utilities::decodeHtmlEntities($program['title']);
}

$pageTitle = ($program['title'] ?? 'Program') . ' - Program Details';
$currentPage = 'programs';

require_once __DIR__ . '/includes/layout-vars.php';

$apiPrograms = $basePath . '/public/api/programs.php';
$csrfToken = CsrfMiddleware::getToken();
$programShareUrl = headcount_program_portal_url($config, $programId);
$programGuestRegisterUrl = headcount_program_guest_register_url($config, $programId);
$guestRegistrationEnabled = !empty($program['allow_guest_registration']);
$programQuestions = $svc->getQuestions($programId);
$programWeeks = $svc->listWeeks($programId);
$programRegistrationMode = (string) ($program['registration_mode'] ?? 'whole_program');
$apiProgramExport = $basePath . '/public/api/program-registrants-export.php';
$apiMemberSearch = $basePath . '/public/api/search.php';
$programShareQrSrc = $basePath . '/public/api/program-share-qr.php?id=' . $programId;
$programShareQrDownloadHref = $basePath . '/public/api/program-share-qr.php?id=' . $programId . '&download=1';

require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in" x-data="programDetailsApp()" x-init="init()">
    <?php
    $pageHeaderBreadcrumb = [
        ['label' => 'Programs', 'url' => $adminBase . '/index.php?page=programs'],
        ['label' => $program['title'] ?? 'Program'],
    ];
    $pageHeaderTitle = e($program['title'] ?? 'Program');
    $pageHeaderSubtitle = 'Manage registrants, session attendance, and sharing.';
    ob_start(); ?>
    <a href="<?= e($adminBase . '/index.php?page=programs') ?>" class="page-header-btn-secondary whitespace-nowrap flex-shrink-0">Back to Programs</a>
    <a href="<?= e($adminBase . '/index.php?page=program-edit&id=' . $programId) ?>" class="page-header-btn-primary whitespace-nowrap flex-shrink-0">Edit program</a>
    <button type="button" @click="deleteProgram()" class="page-header-btn-secondary whitespace-nowrap flex-shrink-0 text-rose-700 border-rose-200 hover:bg-rose-50 dark:text-rose-300 dark:border-rose-900/40 dark:hover:bg-rose-950/30">Delete program</button>
    <?php $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php'; ?>

    <div class="mb-6">
        <?php
        $cardTabs = [
            ['id' => 'overview', 'label' => 'Overview', 'active' => true],
            ['id' => 'registrants', 'label' => 'Registrants', 'click' => 'loadRegistrants()'],
            ['id' => 'questions', 'label' => 'Questions', 'click' => 'loadRegistrants()'],
            ['id' => 'sessions', 'label' => 'Sessions & attendance', 'click' => 'loadSessions()'],
            ['id' => 'announcement', 'label' => 'Announcement', 'click' => 'onAnnounceTab()'],
            ['id' => 'share', 'label' => 'Share'],
        ];
        $cardTabsVar = 'activeTab';
        $cardTabsParentScope = true;
        require __DIR__ . '/components/card-tabs.php';
        unset($cardTabs, $cardTabsVar, $cardTabsParentScope);
        ?>
    </div>

    <!-- Overview -->
    <div x-show="activeTab === 'overview'" x-cloak class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <?php
            $statLabel = 'Status';
            $statValue = ucfirst($program['status'] ?? 'draft');
            $statTrend = null;
            $statTrendLabel = 'Program status';
            $statAccent = 'brand';
            $statIcon = 'layers';
            require __DIR__ . '/components/stat-card-trend.php';
            $pt = $program['pricing_type'] ?? 'free';
            $statLabel = 'Pricing';
            $statValue = $pt === 'free' ? 'Free' : ($pt === 'recurring' ? 'Recurring' : '$' . number_format((float) ($program['price_amount'] ?? 0), 2));
            $statTrend = null;
            $statTrendLabel = 'Pricing model';
            $statAccent = 'success';
            $statIcon = 'currency';
            require __DIR__ . '/components/stat-card-trend.php';
            ?>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-warning-50 text-warning-600 dark:bg-warning-500/15 dark:text-warning-400">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                </div>
                <div class="mt-5">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Registrants</span>
                    <h4 class="mt-2 text-title-xl font-bold leading-none tracking-tight text-gray-800 dark:text-white/90" x-text="registrants.length + ''">—</h4>
                    <p class="mt-1 text-theme-xs text-gray-400 dark:text-gray-500">Active enrollments</p>
                </div>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-sky-50 text-sky-600 dark:bg-sky-500/15 dark:text-sky-400">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                </div>
                <div class="mt-5">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Upcoming sessions</span>
                    <h4 class="mt-2 text-title-xl font-bold leading-none tracking-tight text-gray-800 dark:text-white/90" x-text="sessions.length + ''">—</h4>
                    <p class="mt-1 text-theme-xs text-gray-400 dark:text-gray-500">In date range</p>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] space-y-4">
            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider">Program details</h3>
            <?php if (!empty($program['category_name'])): ?>
            <p class="text-sm text-gray-600 dark:text-gray-300"><span class="font-semibold text-gray-800 dark:text-gray-100">Category:</span> <?= e($program['category_name']) ?></p>
            <?php endif; ?>
            <?php if (!empty($program['location'])): ?>
            <p class="text-sm text-gray-600 dark:text-gray-300"><span class="font-semibold text-gray-800 dark:text-gray-100">Location:</span> <?= e($program['location']) ?></p>
            <?php endif; ?>
            <?php if (!empty($program['session_start_time'])): ?>
            <p class="text-sm text-gray-600 dark:text-gray-300"><span class="font-semibold text-gray-800 dark:text-gray-100">Schedule:</span>
                <?= e(substr((string) $program['session_start_time'], 0, 5)) ?>
                <?php if (!empty($program['session_end_time'])): ?>
                    – <?= e(substr((string) $program['session_end_time'], 0, 5)) ?>
                <?php endif; ?>
            </p>
            <?php endif; ?>
            <?php if (!empty($program['description'])): ?>
            <div class="text-sm text-gray-600 prose max-w-none dark:text-gray-300"><?= nl2br(e(Utilities::decodeHtmlEntities(strip_tags((string) $program['description'])))) ?></div>
            <?php endif; ?>
            <div class="flex flex-wrap gap-2 pt-2">
                <a href="<?= e($programShareUrl) ?>" target="_blank" rel="noopener" class="btn-secondary text-sm">Open portal page</a>
                <a href="<?= e($adminBase . '/index.php?page=program-edit&id=' . $programId) ?>" class="btn-primary text-sm">Edit settings</a>
            </div>
        </div>
    </div>

    <!-- Registrants -->
    <div x-show="activeTab === 'registrants'" x-cloak class="space-y-6">
        <div class="rounded-2xl border border-dashed border-brand-200 bg-brand-50/40 p-5 dark:border-brand-900/40 dark:bg-brand-950/20">
            <h3 class="text-sm font-bold text-gray-700 uppercase tracking-wider mb-1 dark:text-gray-200">Add sponsored participant</h3>
            <p class="text-sm text-gray-600 mb-4 dark:text-gray-400">Enroll anyone whose fee is covered by sponsorship. They are added directly to the program register without online payment. New people receive an email to complete their account.</p>

            <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider mb-2 dark:text-gray-400">Search existing member</h4>
            <div class="flex flex-col sm:flex-row gap-2 max-w-2xl items-stretch sm:items-center mb-3">
                <input type="search" x-model="sponsoredSearchQuery" @keyup.enter="searchMembersForSponsored()"
                       placeholder="Search by name or email (min 2 chars)…"
                       class="flex-1 rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-800">
                <button type="button" class="btn-secondary text-sm whitespace-nowrap" :disabled="sponsoredSearchLoading" @click="searchMembersForSponsored()">Search</button>
            </div>
            <p x-show="sponsoredSearchError" class="text-xs text-rose-600 mb-2" x-text="sponsoredSearchError"></p>
            <div x-show="sponsoredSearchResults.length > 0" class="rounded-xl border border-gray-200 divide-y divide-gray-100 max-w-2xl overflow-hidden mb-4 dark:border-gray-700 dark:divide-gray-800">
                <template x-for="m in sponsoredSearchResults" :key="m.id">
                    <div class="flex items-center justify-between gap-2 px-3 py-2 text-sm bg-white dark:bg-gray-800">
                        <div class="min-w-0">
                            <div class="font-medium text-gray-900 truncate dark:text-white" x-text="m.name || ((m.first_name || '') + ' ' + (m.last_name || '')).trim()"></div>
                            <div class="text-xs text-gray-500 truncate dark:text-gray-400" x-text="m.subtitle || m.email || ''"></div>
                        </div>
                        <button type="button" class="shrink-0 text-xs font-bold text-brand-600 hover:underline disabled:opacity-50"
                                :disabled="sponsoredSaving" @click="selectSponsoredMember(m)">Select</button>
                    </div>
                </template>
            </div>
            <div x-show="sponsoredSelectedMember" class="max-w-2xl space-y-3 rounded-xl border border-gray-200 bg-white p-4 mb-5 dark:border-gray-700 dark:bg-gray-800">
                <p class="text-sm text-gray-700 dark:text-gray-200">
                    Adding <strong x-text="(sponsoredSelectedMember?.name || ((sponsoredSelectedMember?.first_name || '') + ' ' + (sponsoredSelectedMember?.last_name || '')).trim())"></strong>
                </p>
                <div x-show="programUsesSelectWeeks && programWeeks.length > 0">
                    <label class="mb-1.5 block text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Weeks</label>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="w in programWeeks" :key="'sel-' + w.id">
                            <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm dark:border-gray-700">
                                <input type="checkbox" :value="String(w.id)" x-model="sponsoredWeekIds">
                                <span x-text="w.title || ('Week ' + w.id)"></span>
                            </label>
                        </template>
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Note (optional)</label>
                    <input type="text" x-model="sponsoredNote" placeholder="e.g. Youth scholarship fund"
                           class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn-primary text-sm" :disabled="sponsoredSaving" @click="addSponsoredEnrollment('member')">
                        <span x-show="!sponsoredSaving">Add to program</span>
                        <span x-show="sponsoredSaving">Adding…</span>
                    </button>
                    <button type="button" class="btn-secondary text-sm" @click="clearSponsoredSelection()">Cancel</button>
                </div>
            </div>

            <div class="max-w-2xl rounded-xl border border-dashed border-gray-200 bg-white/80 p-4 space-y-3 dark:bg-gray-800/50 dark:border-gray-700">
                <h4 class="text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Add someone new</h4>
                <p class="text-xs text-gray-500 dark:text-gray-400">Not in the member list? Enter their details and we will create an account and email them to complete their profile.</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <input type="text" x-model="sponsoredGuestForm.first_name" placeholder="First name"
                           class="rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900">
                    <input type="text" x-model="sponsoredGuestForm.last_name" placeholder="Last name"
                           class="rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900">
                </div>
                <input type="email" x-model="sponsoredGuestForm.email" placeholder="Email address"
                       class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900">
                <div x-show="programUsesSelectWeeks && programWeeks.length > 0">
                    <label class="mb-1.5 block text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Weeks</label>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="w in programWeeks" :key="'guest-' + w.id">
                            <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-1.5 text-sm dark:border-gray-700">
                                <input type="checkbox" :value="String(w.id)" x-model="sponsoredGuestWeekIds">
                                <span x-text="w.title || ('Week ' + w.id)"></span>
                            </label>
                        </template>
                    </div>
                </div>
                <div>
                    <label class="mb-1.5 block text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Note (optional)</label>
                    <input type="text" x-model="sponsoredGuestNote" placeholder="e.g. Youth scholarship fund"
                           class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" class="btn-primary text-sm" :disabled="sponsoredSaving" @click="addSponsoredEnrollment('guest')">
                        <span x-show="!sponsoredSaving">Add to program</span>
                        <span x-show="sponsoredSaving">Adding…</span>
                    </button>
                    <span x-show="sponsoredSuccess" class="text-xs text-emerald-700 dark:text-emerald-300" x-text="sponsoredSuccess"></span>
                    <span x-show="sponsoredError" class="text-xs text-rose-600" x-text="sponsoredError"></span>
                </div>
            </div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:px-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Registrants</h3>
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-500 dark:text-gray-400" x-show="loadingRegistrants">Loading…</span>
                    <a :href="exportUrl" class="btn-secondary text-sm py-2 px-4" x-show="registrants.length > 0">Export CSV</a>
                </div>
            </div>
            <div class="w-full overflow-x-auto custom-scrollbar" x-show="registrants.length > 0">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-y border-gray-100 dark:border-gray-800">
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Member</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Weeks</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Joined</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</p></th>
                            <th class="py-3 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Answers</p></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="r in registrants" :key="r.user_id || r.id">
                            <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.02] dark:bg-gray-800">
                                <td class="py-3 pr-4">
                                    <div class="flex items-center gap-3">
                                        <span class="ta-avatar ta-avatar-sm bg-brand-100 text-brand-700" x-text="((r.first_name || '').charAt(0) + (r.last_name || '').charAt(0)).toUpperCase() || '?'"></span>
                                        <div class="min-w-0">
                                            <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="(r.first_name || '') + ' ' + (r.last_name || '')"></span>
                                            <span class="block text-theme-xs text-gray-500 dark:text-gray-400" x-text="r.email || '—'"></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 pr-4 text-theme-sm text-gray-600 dark:text-gray-300" x-text="r.weeks_label || (r.weeks && r.weeks.length ? r.weeks.map(w => w.title).join(', ') : 'All weeks')"></td>
                                <td class="py-3 pr-4 text-theme-sm text-gray-500 dark:text-gray-400" x-text="r.joined_at ? r.joined_at.slice(0, 10) : '—'"></td>
                                <td class="py-3 pr-4 text-theme-sm">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold"
                                          :class="(r.enrollment_source || '') === 'sponsored' ? 'bg-indigo-50 text-indigo-800 dark:bg-indigo-950/40 dark:text-indigo-200' : 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300'"
                                          x-text="(r.enrollment_source || '') === 'sponsored' ? 'Sponsored' : 'Active'"></span>
                                </td>
                                <td class="py-3 text-theme-sm text-gray-600 dark:text-gray-300">
                                    <template x-if="!(r.question_answers || []).length">
                                        <span class="text-gray-400">—</span>
                                    </template>
                                    <template x-if="(r.question_answers || []).length">
                                        <ul class="space-y-1 text-xs">
                                            <template x-for="qa in (r.question_answers || [])" :key="qa.question_id">
                                                <li><span class="font-semibold text-gray-700 dark:text-gray-200" x-text="qa.question_text + ':'"></span> <span x-text="qa.answer_display || qa.answer_text || '—'"></span></li>
                                            </template>
                                        </ul>
                                    </template>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="py-10 text-center text-sm text-gray-500 dark:text-gray-400" x-show="!loadingRegistrants && registrants.length === 0">No confirmed registrants yet.</div>
        </div>

        <div class="overflow-hidden rounded-2xl border border-amber-200 bg-amber-50/30 px-4 pb-3 pt-4 shadow-theme-sm dark:border-amber-900/40 dark:bg-amber-950/10 sm:px-6" x-show="pendingRegistrants.length > 0">
            <div class="mb-4">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Incomplete payments</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">These people started checkout but are not on the register until payment completes. A reminder email is sent automatically after two days.</p>
            </div>
            <div class="w-full overflow-x-auto custom-scrollbar">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-y border-amber-100 dark:border-amber-900/30">
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Member</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Started</p></th>
                            <th class="py-3 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</p></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-amber-100 dark:divide-amber-900/30">
                        <template x-for="r in pendingRegistrants" :key="'pending-' + (r.registration_id || r.id)">
                            <tr>
                                <td class="py-3 pr-4">
                                    <div class="min-w-0">
                                        <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="(r.first_name || '') + ' ' + (r.last_name || '')"></span>
                                        <span class="block text-theme-xs text-gray-500 dark:text-gray-400" x-text="r.email || '—'"></span>
                                    </div>
                                </td>
                                <td class="py-3 pr-4 text-theme-sm text-gray-500 dark:text-gray-400" x-text="r.started_at ? r.started_at.slice(0, 10) : '—'"></td>
                                <td class="py-3 text-theme-sm">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Payment pending</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Questions (registration answers grouped by question) -->
    <div x-show="activeTab === 'questions'" x-cloak class="space-y-4">
        <div x-show="loadingRegistrants" class="py-12 text-center">
            <div class="inline-block animate-spin w-8 h-8 border-4 border-brand-500 border-t-transparent rounded-full"></div>
            <p class="mt-4 text-gray-500 font-bold uppercase tracking-widest text-xs dark:text-gray-400">Loading...</p>
        </div>
        <div x-show="!loadingRegistrants && questionGroups.length === 0" class="py-12 text-center text-gray-500 bento-card dark:text-gray-400">
            <p>No registration question responses yet for this program.</p>
        </div>
        <div x-show="!loadingRegistrants && questionGroups.length > 0" class="space-y-3">
            <template x-for="q in questionGroups" :key="q.key">
                <details class="bento-card overflow-hidden group" x-data="programQuestionAnswerBlock(q.key)">
                    <summary class="px-5 py-4 cursor-pointer list-none flex items-start justify-between gap-4 hover:bg-gray-50/80 transition-colors marker:content-none [&::-webkit-details-marker]:hidden dark:bg-gray-800">
                        <span class="font-bold text-gray-900 text-sm leading-snug pr-2 dark:text-white" x-text="q.question_text"></span>
                        <span class="shrink-0 text-[10px] font-black uppercase tracking-wider px-2.5 py-1 rounded-full bg-brand-50 text-brand-700" x-text="q.answers.length + ' answers'"></span>
                    </summary>
                    <div class="border-t border-gray-200 px-5 pb-4 pt-3 dark:border-gray-700">
                        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between mb-3">
                            <div class="flex flex-col sm:flex-row gap-2 flex-1 min-w-0">
                                <input type="search" x-model="search" placeholder="Search name or answer..." class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm dark:border-gray-700 dark:text-white sm:max-w-md">
                                <select x-model="answerFilter" class="min-w-[10rem] w-full rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm dark:bg-gray-800 dark:border-gray-700 sm:w-auto">
                                    <option value="all">All answers</option>
                                    <template x-for="opt in uniqueAnswers" :key="opt === '' ? '__blank__' : opt">
                                        <option :value="opt === '' ? '__EMPTY__' : opt" x-text="opt === '' ? '(blank)' : opt"></option>
                                    </template>
                                </select>
                            </div>
                            <p class="text-xs text-gray-500 shrink-0 dark:text-gray-400">
                                Showing <span class="font-bold text-gray-700 dark:text-gray-200" x-text="filteredRows.length"></span>
                                of <span x-text="q.answers.length"></span>
                            </p>
                        </div>
                        <div x-show="filteredRows.length > 0" class="overflow-hidden rounded-xl border border-gray-200 shadow-card dark:border-gray-700">
                            <div class="overflow-x-auto">
                                <table class="w-full text-sm table-fixed">
                                    <thead class="bg-gray-50 border-b border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider w-[38%] dark:text-gray-400">Name</th>
                                            <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Answer</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                        <template x-for="(row, idx) in filteredRows" :key="idx + '-' + (row.name || '') + '-' + (row.answer || '')">
                                            <tr class="hover:bg-gray-50/80 dark:bg-gray-800">
                                                <td class="px-4 py-3 font-semibold text-gray-900 align-top break-words min-w-0 dark:text-white" x-text="row.name"></td>
                                                <td class="px-4 py-3 text-gray-800 align-top break-words min-w-0 dark:text-gray-100" x-text="row.answer"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div x-show="filteredRows.length === 0" class="rounded-xl border border-dashed border-gray-200 py-8 text-center text-sm text-gray-500 dark:text-gray-400 dark:border-gray-700">
                            No rows match your search or filter.
                        </div>
                    </div>
                </details>
            </template>
        </div>
    </div>

    <!-- Sessions & attendance -->
    <div x-show="activeTab === 'sessions'" x-cloak class="space-y-4">
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <label class="mb-1.5 block text-sm font-semibold text-gray-700 dark:text-gray-200">Session</label>
            <select x-model="selectedSessionId" @change="loadRoster()" class="w-full max-w-md rounded-xl border border-gray-200 px-3 py-2.5 text-sm dark:border-gray-700">
                <option value="">— Select a session —</option>
                <template x-for="s in sessions" :key="s.id">
                    <option :value="String(s.id)" x-text="sessionLabel(s)"></option>
                </template>
            </select>
            <p class="mt-2 text-xs text-amber-700" x-show="!loadingSessions && sessions.length === 0">No sessions found. Generate sessions from the program editor.</p>
        </div>

        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:px-6" x-show="selectedSessionId && roster">
            <div class="mb-4">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90" x-text="roster?.session ? (roster.session.program_title + ' — ' + roster.session.session_date) : ''"></h3>
            </div>
            <div class="w-full overflow-x-auto custom-scrollbar">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-y border-gray-100 dark:border-gray-800">
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Member</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</p></th>
                            <th class="py-3 pr-4 text-right"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Mark as</p></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="row in (roster?.registrants || [])" :key="row.user_id">
                            <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.02] dark:bg-gray-800">
                                <td class="py-3 pr-4">
                                    <div class="flex items-center gap-3">
                                        <span class="ta-avatar ta-avatar-sm bg-brand-100 text-brand-700" x-text="((row.first_name || '').charAt(0) + (row.last_name || '').charAt(0)).toUpperCase() || '?'"></span>
                                        <div class="min-w-0">
                                            <span class="block text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="(row.first_name || '') + ' ' + (row.last_name || '')"></span>
                                            <span class="block text-theme-xs text-gray-500 dark:text-gray-400" x-text="row.email || '—'"></span>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 pr-4">
                                    <span class="inline-flex rounded-full px-2.5 py-0.5 text-theme-xs font-medium" :class="statusClass(row.attendance_status)" x-text="statusLabel(row.attendance_status)"></span>
                                </td>
                                <td class="py-3 pr-4 text-right">
                                    <div class="flex justify-end gap-1.5 flex-wrap">
                                        <button type="button" @click="setStatus(row.user_id, 'present')" :disabled="savingUser === row.user_id" class="rounded-lg border border-green-200 bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700 transition-colors hover:bg-green-100 disabled:opacity-40 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300">Present</button>
                                        <button type="button" @click="setStatus(row.user_id, 'absent')" :disabled="savingUser === row.user_id" class="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700 transition-colors hover:bg-red-100 disabled:opacity-40 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300">Absent</button>
                                        <button type="button" @click="setStatus(row.user_id, 'excused')" :disabled="savingUser === row.user_id" class="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 transition-colors hover:bg-amber-100 disabled:opacity-40 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">Excused</button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="py-10 text-center text-sm text-gray-500 dark:text-gray-400" x-show="roster && roster.registrants && roster.registrants.length === 0">No active registrants for this session.</div>
        </div>
    </div>

    <!-- Announcement -->
    <div x-show="activeTab === 'announcement'" x-cloak class="space-y-6">
        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-1">Send announcement</h3>
            <p class="text-sm text-gray-500 mb-5 dark:text-gray-400">Email all active registrants of this program. Compose in the editor — the message is sent as HTML.</p>
            <div class="space-y-4 max-w-3xl">
                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-gray-700 dark:text-gray-200">Subject</label>
                    <input type="text" x-model="announce.subject" placeholder="e.g. Update about {program_name}"
                           class="ta-input w-full">
                </div>
                <div>
                    <label class="mb-1.5 block text-sm font-semibold text-gray-700 dark:text-gray-200">Message</label>
                    <div id="program-announce-body-wrap" class="rounded-xl border border-gray-200 overflow-hidden bg-white dark:bg-gray-800 dark:border-gray-700">
                        <textarea id="program-announce-body" class="w-full text-sm" rows="6" x-model="announce.body" placeholder="Write your announcement…"></textarea>
                    </div>
                    <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Merge tags: <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">{first_name}</code>, <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">{program_name}</code>, <code class="rounded bg-gray-100 px-1 dark:bg-gray-800">{next_session_date}</code></p>
                </div>
                <div class="flex flex-wrap items-center gap-3 pt-1">
                    <button type="button" @click="sendAnnounce()" :disabled="sendingAnnounce" class="btn-primary text-sm py-2 px-4">
                        <span x-text="sendingAnnounce ? 'Sending…' : 'Send to active registrants'"></span>
                    </button>
                    <p class="text-sm text-emerald-600 dark:text-emerald-400" x-show="announceSuccess" x-cloak x-text="announceSuccess"></p>
                    <p class="text-sm text-red-600 dark:text-red-400" x-show="announceError" x-text="announceError" x-cloak></p>
                </div>
            </div>
        </div>

        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider">Email activity for this program</h3>
                <button type="button" @click="loadEmailLogs()" class="text-xs font-bold text-brand-600 hover:underline">Refresh</button>
            </div>
            <div x-show="emailLogsLoading" class="py-6 text-center text-gray-500 text-sm dark:text-gray-400">
                Loading email activity...
            </div>
            <div x-show="!emailLogsLoading && emailLogs.length === 0" class="py-2 text-sm text-gray-500 dark:text-gray-400">
                No messages logged yet for this program.
            </div>
            <div x-show="!emailLogsLoading && emailLogs.length > 0" class="-mx-4 overflow-hidden rounded-xl border border-gray-200 shadow-card sm:mx-0 dark:border-gray-700">
                <div class="overflow-x-auto">
                <table class="min-w-full text-xs">
                    <thead class="bg-gray-50 border-b border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                        <tr>
                            <th class="px-4 py-2 text-left font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">When</th>
                            <th class="px-4 py-2 text-left font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Subject</th>
                            <th class="px-4 py-2 text-left font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Recipient</th>
                            <th class="px-4 py-2 text-left font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Type</th>
                            <th class="px-4 py-2 text-left font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <template x-for="log in emailLogs" :key="log.id">
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap text-gray-500 dark:text-gray-400" x-text="formatLogDate(log.sent_at || log.created_at)"></td>
                                <td class="px-4 py-2 max-w-[220px] truncate" x-text="log.subject || '\u2014'"></td>
                                <td class="px-4 py-2 max-w-[200px] truncate">
                                    <span x-text="(log.recipient_first_name || log.recipient_last_name) ? ((log.recipient_first_name || '') + ' ' + (log.recipient_last_name || '') + ' | ' + (log.recipient_email || '')) : (log.recipient_email || '\u2014')"></span>
                                </td>
                                <td class="px-4 py-2 whitespace-nowrap text-gray-600 dark:text-gray-300" x-text="log.email_type || 'custom'"></td>
                                <td class="px-4 py-2 whitespace-nowrap">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold uppercase tracking-wider"
                                          :class="log.status === 'sent' ? 'bg-emerald-50 text-emerald-700' : (log.status === 'failed' ? 'bg-rose-50 text-rose-700' : 'bg-gray-50 text-gray-600')"
                                          x-text="log.status || 'queued'"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Share -->
    <div x-show="activeTab === 'share'" x-cloak>
        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] space-y-6">
            <div>
                <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-1">Member portal link</h3>
                <p class="text-xs text-gray-500 mb-4 dark:text-gray-400">For signed-in members — scan or share the QR code for the portal program page.</p>
                <div class="flex flex-col sm:flex-row gap-6 items-start">
                    <div class="shrink-0 rounded-xl border border-gray-200 bg-white p-2 shadow-card dark:bg-gray-800 dark:border-gray-700">
                        <img src="<?= e($programShareQrSrc) ?>" width="200" height="200" alt="QR code for program" class="w-[200px] h-[200px] object-contain">
                    </div>
                    <div class="flex-1 min-w-0 space-y-3 w-full">
                        <div>
                            <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Portal link</div>
                            <div class="break-all rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-800 dark:bg-gray-800 dark:text-gray-100 dark:border-gray-700"><?= e($programShareUrl) ?></div>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" @click="copyShareUrl()" class="btn-primary text-sm">Copy link</button>
                            <a href="<?= e($programShareUrl) ?>" target="_blank" rel="noopener" class="btn-secondary text-sm">Open page</a>
                            <a href="<?= e($programShareQrDownloadHref) ?>" class="btn-secondary text-sm inline-flex items-center gap-2">Download QR</a>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($guestRegistrationEnabled): ?>
            <div class="border-t border-gray-200 pt-6 dark:border-gray-700">
                <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-1">Guest registration link</h3>
                <p class="text-xs text-gray-500 mb-4 dark:text-gray-400">For non-members — no portal login required. Share this link on flyers, email, or social media.</p>
                <div>
                    <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Guest link</div>
                    <div class="break-all rounded-xl border border-indigo-200 bg-indigo-50/50 px-3 py-2 font-mono text-sm text-gray-800 dark:border-indigo-900/40 dark:bg-indigo-950/20 dark:text-gray-100"><?= e($programGuestRegisterUrl) ?></div>
                </div>
                <div class="flex flex-wrap gap-2 mt-3">
                    <button type="button" @click="copyGuestShareUrl()" class="btn-primary text-sm">Copy guest link</button>
                    <a href="<?= e($programGuestRegisterUrl) ?>" target="_blank" rel="noopener" class="btn-secondary text-sm">Open guest page</a>
                </div>
            </div>
            <?php else: ?>
            <div class="border-t border-gray-200 pt-6 dark:border-gray-700">
                <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-1">Guest registration</h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Guest registration is off for this program.
                    <a href="<?= e($adminBase . '/index.php?page=program-edit&id=' . $programId) ?>" class="font-semibold text-brand-600 hover:text-brand-800 dark:text-brand-400">Enable it in program settings</a>
                    to get a public registration link for non-members.
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const PROGRAMS_API_URL = <?= json_encode($apiPrograms) ?>;
const PROGRAMS_CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

async function headcountDeleteProgram(programId, programTitle, redirectUrl) {
    const title = (programTitle || 'this program').trim();
    const confirmed = typeof confirmAction === 'function'
        ? await confirmAction({
            title: 'Delete "' + title + '"?',
            message: 'The program will be archived and removed from the portal. Registrations and history are kept. You can find it later with Status → Archived.',
            type: 'danger',
            okText: 'Delete',
            cancelText: 'Cancel',
        })
        : window.confirm('Delete "' + title + '"?\n\nThe program will be archived and removed from the portal. Registrations and history are kept. You can find it later with Status → Archived.');
    if (!confirmed) {
        return;
    }
    try {
        const r = await fetch(PROGRAMS_API_URL + '?action=delete', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': PROGRAMS_CSRF_TOKEN,
            },
            body: JSON.stringify({ action: 'delete', id: programId, csrf_token: PROGRAMS_CSRF_TOKEN }),
        });
        const j = await r.json();
        if (j.success) {
            window.location.href = redirectUrl || window.location.href;
            if (!redirectUrl) {
                window.location.reload();
            }
            return;
        }
        window.alert(j.message || 'Could not delete program');
    } catch (e) {
        console.error(e);
        window.alert('An error occurred while deleting the program.');
    }
}

function programDetailsApp() {
    const programId = <?= (int) $programId ?>;
    const apiPrograms = <?= json_encode($apiPrograms) ?>;
    const apiProgramExport = <?= json_encode($apiProgramExport) ?>;
    const programQuestions = <?= json_encode($programQuestions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const programShareUrl = <?= json_encode($programShareUrl) ?>;
    const programGuestShareUrl = <?= json_encode($programGuestRegisterUrl) ?>;
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const programTitle = <?= json_encode($program['title'] ?? 'Program', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const programsListUrl = <?= json_encode($adminBase . '/index.php?page=programs') ?>;
    const apiMemberSearch = <?= json_encode($apiMemberSearch) ?>;
    const apiBase = <?= json_encode(rtrim($basePath, '/') . '/public/api') ?>;
    const programWeeks = <?= json_encode($programWeeks, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const programUsesSelectWeeks = <?= json_encode($programRegistrationMode === 'select_weeks') ?>;

    return {
        activeTab: 'overview',
        registrants: [],
        pendingRegistrants: [],
        programWeeks: programWeeks || [],
        programUsesSelectWeeks: !!programUsesSelectWeeks,
        sponsoredSearchQuery: '',
        sponsoredSearchResults: [],
        sponsoredSearchLoading: false,
        sponsoredSearchError: '',
        sponsoredSelectedMember: null,
        sponsoredWeekIds: [],
        sponsoredNote: '',
        sponsoredGuestForm: { first_name: '', last_name: '', email: '' },
        sponsoredGuestWeekIds: [],
        sponsoredGuestNote: '',
        sponsoredSaving: false,
        sponsoredSuccess: '',
        sponsoredError: '',
        programQuestions: programQuestions || [],
        sessions: [],
        roster: null,
        selectedSessionId: '',
        loadingRegistrants: false,
        loadingSessions: false,
        savingUser: null,
        announce: {
            subject: <?= json_encode('Update about {program_name}', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            body: <?= json_encode('<p>Hi {first_name},</p><p>We have an update about <strong>{program_name}</strong>.</p><p>Next session: {next_session_date}</p>', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        },
        sendingAnnounce: false,
        announceSuccess: '',
        announceError: '',
        emailLogs: [],
        emailLogsLoading: false,
        get exportUrl() {
            return apiProgramExport + '?program_id=' + programId;
        },
        getQuestionGroup(groupKey) {
            return (this.questionGroups || []).find((g) => g.key === groupKey)
                || { key: groupKey, question_text: '', answers: [] };
        },
        buildQuestionGroups() {
            const list = this.registrants || [];
            const configuredQuestions = this.programQuestions || [];
            const groups = new Map();
            const order = [];
            for (const q of configuredQuestions) {
                const qid = q && q.id != null && q.id !== '' ? Number(q.id) : null;
                const key = (qid !== null && !Number.isNaN(qid)) ? ('id:' + qid) : ('t:' + String((q && q.question_text) || ''));
                if (!groups.has(key)) {
                    const sort = q && q.sort_order != null ? Number(q.sort_order) : 999999;
                    groups.set(key, {
                        key,
                        question_id: qid,
                        question_text: (q && q.question_text) || '',
                        question_sort_order: sort,
                        answers: []
                    });
                    order.push(key);
                }
            }
            for (const reg of list) {
                const name = ((reg.first_name || '') + ' ' + (reg.last_name || '')).trim() || '\u2014';
                for (const qa of (reg.question_answers || [])) {
                    const qid = qa.question_id != null && qa.question_id !== '' ? Number(qa.question_id) : null;
                    const key = (qid !== null && !Number.isNaN(qid)) ? ('id:' + qid) : ('t:' + String(qa.question_text || ''));
                    if (!groups.has(key)) {
                        const sort = qa.question_sort_order != null ? Number(qa.question_sort_order) : 999999;
                        groups.set(key, {
                            key,
                            question_id: qid,
                            question_text: qa.question_text || '',
                            question_sort_order: sort,
                            answers: []
                        });
                        order.push(key);
                    }
                    const g = groups.get(key);
                    if (qa.question_text && !g.question_text) {
                        g.question_text = qa.question_text;
                    }
                    const ans = (qa.answer_display || qa.answer_text || '').trim();
                    if (ans !== '') {
                        g.answers.push({ name, answer: qa.answer_display || qa.answer_text });
                    }
                }
            }
            const arr = order.map((k) => groups.get(k));
            arr.sort((a, b) => {
                if (a.question_sort_order !== b.question_sort_order) {
                    return a.question_sort_order - b.question_sort_order;
                }
                return (a.question_id || 0) - (b.question_id || 0);
            });
            return arr;
        },
        get questionGroups() {
            return this.buildQuestionGroups();
        },
        programQuestionAnswerBlock(groupKey) {
            const parent = this;
            return {
                groupKey,
                search: '',
                answerFilter: 'all',
                get q() {
                    return parent.getQuestionGroup(groupKey);
                },
                get uniqueAnswers() {
                    const seen = new Set();
                    (this.q.answers || []).forEach((r) => {
                        seen.add(String(r.answer ?? ''));
                    });
                    return Array.from(seen).sort((a, b) => a.localeCompare(b));
                },
                get filteredRows() {
                    let list = [...(this.q.answers || [])];
                    const term = (this.search || '').trim().toLowerCase();
                    if (term) {
                        list = list.filter((r) =>
                            ((r.name || '').toLowerCase().includes(term)) ||
                            String(r.answer ?? '').toLowerCase().includes(term)
                        );
                    }
                    if (this.answerFilter !== 'all') {
                        const want = this.answerFilter === '__EMPTY__' ? '' : this.answerFilter;
                        list = list.filter((r) => String(r.answer ?? '') === want);
                    }
                    return list;
                }
            };
        },
        async init() {
            await Promise.all([this.loadRegistrants(), this.loadSessions()]);
            const self = this;
            this.$watch('activeTab', function(tab) {
                if (tab === 'announcement') {
                    self.onAnnounceTab();
                }
            });
        },
        sessionLabel(s) {
            const t = (s.start_time || '').slice(0, 5);
            return (s.session_date || '') + (t ? ' · ' + t : '');
        },
        statusLabel(st) {
            if (!st) return '—';
            return st.charAt(0).toUpperCase() + st.slice(1);
        },
        statusClass(st) {
            if (st === 'present') return 'bg-green-100 text-green-700';
            if (st === 'absent') return 'bg-red-100 text-red-700';
            if (st === 'excused') return 'bg-amber-100 text-amber-800';
            return 'bg-gray-100 text-gray-600';
        },
        async loadRegistrants() {
            this.loadingRegistrants = true;
            try {
                const [regRes, pendingRes] = await Promise.all([
                    fetch(apiPrograms + '?action=registrants&program_id=' + programId, { credentials: 'same-origin' }),
                    fetch(apiPrograms + '?action=pending_registrants&program_id=' + programId, { credentials: 'same-origin' }),
                ]);
                const j = await regRes.json();
                const pj = await pendingRes.json();
                this.registrants = (j.success && j.registrants) ? j.registrants : [];
                this.pendingRegistrants = (pj.success && pj.pending) ? pj.pending : [];
            } catch (e) {
                this.registrants = [];
                this.pendingRegistrants = [];
            }
            this.loadingRegistrants = false;
        },
        async searchMembersForSponsored() {
            const q = (this.sponsoredSearchQuery || '').trim();
            this.sponsoredSearchError = '';
            this.sponsoredSearchResults = [];
            if (q.length < 2) {
                this.sponsoredSearchError = 'Enter at least 2 characters to search.';
                return;
            }
            this.sponsoredSearchLoading = true;
            try {
                const r = await fetch(apiMemberSearch + '?q=' + encodeURIComponent(q) + '&limit=10', { credentials: 'same-origin' });
                const j = await r.json();
                const members = (j.success && j.members) ? j.members : [];
                const registeredIds = new Set((this.registrants || []).map((row) => Number(row.id || row.user_id || 0)));
                this.sponsoredSearchResults = members.filter((m) => !registeredIds.has(Number(m.id || 0)));
            } catch (e) {
                this.sponsoredSearchError = 'Search failed. Please try again.';
            }
            this.sponsoredSearchLoading = false;
        },
        selectSponsoredMember(member) {
            this.sponsoredSelectedMember = member;
            this.sponsoredWeekIds = [];
            this.sponsoredNote = '';
            this.sponsoredSuccess = '';
            this.sponsoredError = '';
        },
        clearSponsoredSelection() {
            this.sponsoredSelectedMember = null;
            this.sponsoredWeekIds = [];
            this.sponsoredNote = '';
            this.sponsoredSuccess = '';
            this.sponsoredError = '';
        },
        clearSponsoredGuestForm() {
            this.sponsoredGuestForm = { first_name: '', last_name: '', email: '' };
            this.sponsoredGuestWeekIds = [];
            this.sponsoredGuestNote = '';
        },
        async addSponsoredEnrollment(mode) {
            this.sponsoredSaving = true;
            this.sponsoredSuccess = '';
            this.sponsoredError = '';
            try {
                const payload = {
                    action: 'add_sponsored_enrollment',
                    csrf_token: csrfToken,
                    program_id: programId,
                    note: '',
                };
                let weekIds = [];
                if (mode === 'guest') {
                    payload.first_name = (this.sponsoredGuestForm.first_name || '').trim();
                    payload.last_name = (this.sponsoredGuestForm.last_name || '').trim();
                    payload.email = (this.sponsoredGuestForm.email || '').trim();
                    payload.note = (this.sponsoredGuestNote || '').trim();
                    weekIds = this.sponsoredGuestWeekIds || [];
                    if (!payload.first_name || !payload.last_name || !payload.email) {
                        this.sponsoredError = 'First name, last name, and email are required.';
                        return;
                    }
                } else {
                    if (!this.sponsoredSelectedMember) {
                        this.sponsoredError = 'Select a member from search results.';
                        return;
                    }
                    payload.user_id = Number(this.sponsoredSelectedMember.id || 0);
                    payload.note = (this.sponsoredNote || '').trim();
                    weekIds = this.sponsoredWeekIds || [];
                }
                if (this.programUsesSelectWeeks) {
                    payload.week_ids = weekIds.map((id) => parseInt(id, 10)).filter((id) => id > 0);
                }
                const r = await fetch(apiPrograms, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const j = await r.json();
                if (!j.success) {
                    this.sponsoredError = j.message || 'Could not add participant.';
                    return;
                }
                if (j.needs_profile) {
                    this.sponsoredSuccess = 'Added to the program. They will receive an email to complete their account.';
                } else if (j.email_sent) {
                    this.sponsoredSuccess = 'Added to the program and confirmation email sent.';
                } else {
                    this.sponsoredSuccess = 'Added to the program.';
                }
                this.clearSponsoredSelection();
                this.clearSponsoredGuestForm();
                this.sponsoredSearchQuery = '';
                this.sponsoredSearchResults = [];
                await this.loadRegistrants();
            } catch (e) {
                this.sponsoredError = 'Could not add participant.';
            } finally {
                this.sponsoredSaving = false;
            }
        },
        async loadSessions() {
            this.loadingSessions = true;
            try {
                const from = new Date();
                from.setMonth(from.getMonth() - 3);
                const to = new Date();
                to.setMonth(to.getMonth() + 9);
                const qs = new URLSearchParams({
                    action: 'sessions',
                    program_id: String(programId),
                    from: from.toISOString().slice(0, 10),
                    to: to.toISOString().slice(0, 10),
                });
                const r = await fetch(apiPrograms + '?' + qs.toString(), { credentials: 'same-origin' });
                const j = await r.json();
                this.sessions = (j.success && j.sessions) ? j.sessions : [];
            } catch (e) {
                this.sessions = [];
            }
            this.loadingSessions = false;
        },
        async loadRoster() {
            this.roster = null;
            if (!this.selectedSessionId) return;
            try {
                const r = await fetch(apiPrograms + '?action=attendance_roster&session_id=' + encodeURIComponent(this.selectedSessionId), { credentials: 'same-origin' });
                const j = await r.json();
                this.roster = j.success ? j : null;
            } catch (e) {
                this.roster = null;
            }
        },
        async setStatus(userId, status) {
            this.savingUser = userId;
            try {
                const r = await fetch(apiPrograms, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'attendance',
                        session_id: parseInt(this.selectedSessionId, 10),
                        user_id: userId,
                        status: status,
                        csrf_token: csrfToken,
                    }),
                });
                const j = await r.json();
                if (j.success) {
                    await this.loadRoster();
                } else {
                    alert(j.message || 'Could not save attendance.');
                }
            } catch (e) {
                alert('Could not save attendance.');
            }
            this.savingUser = null;
        },
        async copyShareUrl() {
            try {
                await navigator.clipboard.writeText(programShareUrl);
                alert('Portal link copied.');
            } catch (e) {
                prompt('Copy this link:', programShareUrl);
            }
        },
        async copyGuestShareUrl() {
            try {
                await navigator.clipboard.writeText(programGuestShareUrl);
                alert('Guest registration link copied.');
            } catch (e) {
                prompt('Copy this link:', programGuestShareUrl);
            }
        },
        async deleteProgram() {
            await headcountDeleteProgram(programId, programTitle, programsListUrl);
        },
        async sendAnnounce() {
            this.announceSuccess = '';
            this.announceError = '';
            this.flushAnnounceHtml();
            const subject = String(this.announce.subject || '').trim();
            const body = this.sanitizeAnnounceHtml(this.announce.body || '');
            this.announce.body = body;
            const bodyText = body.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim();
            if (!subject || !bodyText) {
                this.announceError = 'Subject and message body are required.';
                return;
            }
            this.sendingAnnounce = true;
            try {
                const r = await fetch(apiPrograms + '?action=announce', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: csrfToken,
                        program_id: programId,
                        subject: subject,
                        body: body,
                    }),
                });
                const j = await r.json();
                if (j.success) {
                    const sent = j.result && j.result.sent != null ? j.result.sent : null;
                    this.announceSuccess = sent != null ? ('Announcement sent (' + sent + ').') : 'Announcement sent.';
                    setTimeout(() => { this.announceSuccess = ''; }, 5000);
                    this.loadEmailLogs();
                } else {
                    this.announceError = j.message || 'Could not send announcement.';
                }
            } catch (e) {
                this.announceError = 'Could not send announcement.';
            }
            this.sendingAnnounce = false;
        },
        onAnnounceTab() {
            this.$nextTick(() => this.initAnnounceWysiwyg());
            this.loadEmailLogs();
        },
        initAnnounceWysiwyg() {
            const ta = document.getElementById('program-announce-body');
            if (!ta || typeof window.initWYSIWYG !== 'function') return;
            if (!ta.dataset.quillInitialized) {
                ta.value = this.announce.body || '';
                window.initWYSIWYG('#program-announce-body');
                const quill = window.__quillInstances && window.__quillInstances.get(ta);
                if (quill && typeof headcountInitQuillRichToolbar === 'function' && !ta.dataset.announceRichToolbar) {
                    ta.dataset.announceRichToolbar = '1';
                    headcountInitQuillRichToolbar(quill, {
                        uploadImageUrl: apiBase.replace(/\/+$/, '') + '/upload-email-image.php',
                        uploadVideoUrl: apiBase.replace(/\/+$/, '') + '/upload-email-video.php',
                        csrfToken: csrfToken
                    });
                }
            }
            ta.value = this.announce.body || '';
            ta.dispatchEvent(new Event('sync-to-quill'));
        },
        flushAnnounceHtml() {
            const ta = document.getElementById('program-announce-body');
            if (!ta || !window.__quillInstances) return;
            const quill = window.__quillInstances.get(ta);
            if (!quill) return;
            let html = quill.root.innerHTML;
            if (html === '<p><br></p>') html = '';
            this.announce.body = this.sanitizeAnnounceHtml(html);
            ta.value = this.announce.body;
        },
        sanitizeAnnounceHtml(html) {
            const raw = String(html || '');
            if (!raw) return '';
            try {
                const doc = new DOMParser().parseFromString(raw, 'text/html');
                doc.querySelectorAll('script,style,object,embed,link,meta').forEach((el) => el.remove());
                const nodes = doc.body ? doc.body.querySelectorAll('*') : [];
                nodes.forEach((el) => {
                    [...el.attributes].forEach((attr) => {
                        const n = String(attr.name || '').toLowerCase();
                        if (n.startsWith('on')) el.removeAttribute(attr.name);
                    });
                });
                return doc.body ? doc.body.innerHTML : '';
            } catch (e) {
                return raw
                    .replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, '')
                    .replace(/<style[\s\S]*?>[\s\S]*?<\/style>/gi, '');
            }
        },
        formatLogDate(d) {
            if (!d) return '\u2014';
            const s = String(d).replace(' ', 'T');
            const dt = new Date(s);
            if (isNaN(dt.getTime())) return String(d);
            return dt.toLocaleString();
        },
        async loadEmailLogs() {
            this.emailLogsLoading = true;
            try {
                const res = await fetch(apiPrograms + '?action=email_logs&program_id=' + programId + '&limit=100', { credentials: 'same-origin' });
                const data = await res.json().catch(() => ({ success: false }));
                this.emailLogs = (data.success && Array.isArray(data.logs)) ? data.logs : [];
            } catch (e) {
                this.emailLogs = [];
            }
            this.emailLogsLoading = false;
        },
    };
}
</script>

<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script src="<?= e($basePath) ?>/public/admin/js/quill-rich-toolbar.js"></script>
<style>
#program-announce-body-wrap { max-width: 100%; }
#program-announce-body-wrap .ql-toolbar.ql-snow { border-radius: 0.75rem 0.75rem 0 0; }
#program-announce-body-wrap .ql-container.ql-snow {
    border-radius: 0 0 0.75rem 0.75rem;
    max-width: 100%;
    min-width: 0;
}
#program-announce-body-wrap .ql-editor {
    min-height: 200px;
    font-size: 14px;
    max-width: 100%;
    overflow-wrap: anywhere;
    word-break: break-word;
}
#program-announce-body-wrap .ql-editor * { max-width: 100%; }
#program-announce-body-wrap .ql-editor p,
#program-announce-body-wrap .ql-editor li,
#program-announce-body-wrap .ql-editor a,
#program-announce-body-wrap .ql-editor span {
    overflow-wrap: anywhere;
    word-break: break-word;
}
#program-announce-body-wrap .ql-editor img,
#program-announce-body-wrap .ql-editor video,
#program-announce-body-wrap .ql-editor iframe,
#program-announce-body-wrap .ql-editor table {
    max-width: 100%;
}
</style>
<?php require __DIR__ . '/includes/footer.php'; ?>
