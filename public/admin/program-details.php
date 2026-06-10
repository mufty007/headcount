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

AuthMiddleware::requireAdminOrCoordinator();

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

if (!empty($program['title'])) {
    $program['title'] = Utilities::decodeHtmlEntities($program['title']);
}

$pageTitle = ($program['title'] ?? 'Program') . ' - Program Details';
$currentPage = 'programs';

require_once __DIR__ . '/includes/layout-vars.php';

$apiPrograms = $basePath . '/public/api/programs.php';
$csrfToken = CsrfMiddleware::getToken();
$programShareUrl = headcount_program_portal_url($config, $programId);
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
    <?php $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php'; ?>

    <div class="mb-6">
        <?php
        $cardTabs = [
            ['id' => 'overview', 'label' => 'Overview', 'active' => true],
            ['id' => 'registrants', 'label' => 'Registrants', 'click' => 'loadRegistrants()'],
            ['id' => 'sessions', 'label' => 'Sessions & attendance', 'click' => 'loadSessions()'],
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
    <div x-show="activeTab === 'registrants'" x-cloak>
        <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:px-6">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Active registrants</h3>
                <span class="text-xs text-gray-500 dark:text-gray-400" x-show="loadingRegistrants">Loading…</span>
            </div>
            <div class="w-full overflow-x-auto custom-scrollbar" x-show="registrants.length > 0">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-y border-gray-100 dark:border-gray-800">
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Member</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Joined</p></th>
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
                                <td class="py-3 pr-4 text-theme-sm text-gray-500 dark:text-gray-400" x-text="r.joined_at ? r.joined_at.slice(0, 10) : '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="py-10 text-center text-sm text-gray-500 dark:text-gray-400" x-show="!loadingRegistrants && registrants.length === 0">No active registrants yet.</div>
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

    <!-- Share -->
    <div x-show="activeTab === 'share'" x-cloak>
        <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4">Share program</h3>
            <p class="text-xs text-gray-500 mb-4 dark:text-gray-400">Scan or download the QR code to share the member portal program page.</p>
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
                        <a href="<?= e($programShareQrDownloadHref) ?>" class="btn-secondary text-sm inline-flex items-center gap-2">Download QR</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function programDetailsApp() {
    const programId = <?= (int) $programId ?>;
    const apiPrograms = <?= json_encode($apiPrograms) ?>;
    const programShareUrl = <?= json_encode($programShareUrl) ?>;
    const csrfToken = <?= json_encode($csrfToken) ?>;

    return {
        activeTab: 'overview',
        registrants: [],
        sessions: [],
        roster: null,
        selectedSessionId: '',
        loadingRegistrants: false,
        loadingSessions: false,
        savingUser: null,
        async init() {
            await Promise.all([this.loadRegistrants(), this.loadSessions()]);
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
                const r = await fetch(apiPrograms + '?action=registrants&program_id=' + programId, { credentials: 'same-origin' });
                const j = await r.json();
                this.registrants = (j.success && j.registrants) ? j.registrants : [];
            } catch (e) {
                this.registrants = [];
            }
            this.loadingRegistrants = false;
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
                alert('Link copied.');
            } catch (e) {
                prompt('Copy this link:', programShareUrl);
            }
        },
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
