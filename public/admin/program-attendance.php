<?php
/**
 * Program session attendance roster
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
use Headcount\Middleware\CsrfMiddleware;

// Auth is already handled by index.php router; only call it on direct access
if (empty($_SESSION['user_id'])) {
    AuthMiddleware::requireAdminCoordinatorOrPresenter();
}
$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();

$db = Database::getInstance();
$userData = $db->queryOne('SELECT first_name, last_name, email, role FROM users WHERE id = :id', ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
    'role' => $userData['role'] ?? 'admin',
] : ['name' => 'Admin', 'email' => '', 'role' => 'admin'];

require_once __DIR__ . '/includes/layout-vars.php';
$apiPrograms = $basePath . '/public/api/programs.php';
$csrfToken = CsrfMiddleware::getToken();

$prefillProgramId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;

$pageTitle = 'Program Attendance';
$currentPage = 'program-attendance';
require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in" x-data="programAttendanceApp()" x-init="init()">
    <?php
    $pageHeaderBreadcrumb = [
        ['label' => 'Programs', 'url' => $adminBase . '/index.php?page=programs'],
        ['label' => 'Program Attendance'],
    ];
    $pageHeaderTitle = 'Program Attendance';
    $pageHeaderSubtitle = 'Pick a program and session, then mark each registrant.';
    ob_start(); ?>
    <a href="<?= e($adminBase . '/index.php?page=programs') ?>" class="page-header-btn-secondary whitespace-nowrap flex-shrink-0">
        <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
        Back to Programs
    </a>
    <?php $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php'; ?>

    <!-- Filters Card -->
    <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div>
                <label class="mb-1.5 block text-sm font-semibold text-gray-700 dark:text-gray-300">Program</label>
                <select x-model="selectedProgramId" @change="onProgramChange()" class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">— Select a program —</option>
                    <template x-for="p in programs" :key="p.id">
                        <option :value="String(p.id)" x-text="p.title"></option>
                    </template>
                </select>
                <p class="mt-1 text-xs text-amber-700 dark:text-amber-300" x-show="programs.length === 0 && !loadingPrograms">No programs found. Create one first.</p>
            </div>
            <div>
                <label class="mb-1.5 block text-sm font-semibold text-gray-700 dark:text-gray-300">Session</label>
                <select x-model="selectedSessionId" @change="loadRoster()" class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm text-gray-900 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100" :disabled="!selectedProgramId || loadingSessions">
                    <option value="">— Select a session —</option>
                    <template x-for="s in sessions" :key="s.id">
                        <option :value="String(s.id)" x-text="sessionLabel(s)"></option>
                    </template>
                </select>
            </div>
        </div>
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400" x-show="message" x-text="message"></p>
    </div>

    <!-- Roster Table -->
    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:px-6" x-show="selectedSessionId && roster" x-cloak>
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90" x-text="roster?.session ? (roster.session.program_title + ' — ' + roster.session.session_date) : ''"></h3>
                <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400" x-text="roster?.registrants ? roster.registrants.length + ' registrant(s)' : ''"></p>
            </div>
        </div>
        <div class="w-full overflow-x-auto custom-scrollbar">
            <table class="min-w-full">
                <thead>
                    <tr class="border-y border-gray-100 dark:border-gray-800">
                        <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Member</p></th>
                        <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Attendance</p></th>
                        <th class="py-3 pr-4 text-right"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Mark As</p></th>
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
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-theme-xs font-medium"
                                      :class="statusClass(row.attendance_status)"
                                      x-text="statusLabel(row.attendance_status)"></span>
                            </td>
                            <td class="py-3 pr-4 text-right">
                                <div class="flex justify-end gap-1.5">
                                    <button type="button" @click="setStatus(row.user_id, 'present')" :disabled="savingUser === row.user_id"
                                            class="rounded-lg border border-green-200 bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700 transition-colors hover:bg-green-100 disabled:opacity-40 dark:border-green-800 dark:bg-green-950/40 dark:text-green-300">Present</button>
                                    <button type="button" @click="setStatus(row.user_id, 'absent')" :disabled="savingUser === row.user_id"
                                            class="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700 transition-colors hover:bg-red-100 disabled:opacity-40 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300">Absent</button>
                                    <button type="button" @click="setStatus(row.user_id, 'excused')" :disabled="savingUser === row.user_id"
                                            class="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 transition-colors hover:bg-amber-100 disabled:opacity-40 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">Excused</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div class="py-10 text-center text-sm text-gray-400 dark:text-gray-500" x-show="roster && roster.registrants && roster.registrants.length === 0">
            No active registrants for this program session.
        </div>
    </div>
</div>

<script>
function programAttendanceApp() {
    return {
        programs: [],
        sessions: [],
        roster: null,
        selectedProgramId: '<?= $prefillProgramId > 0 ? (int) $prefillProgramId : '' ?>',
        selectedSessionId: '',
        loadingPrograms: true,
        loadingSessions: false,
        savingUser: null,
        message: '',
        async init() {
            await this.loadPrograms();
            if (this.selectedProgramId) {
                await this.onProgramChange();
            }
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
            if (st === 'present') return 'bg-green-100 text-green-800 dark:bg-green-950/50 dark:text-green-300';
            if (st === 'absent') return 'bg-red-100 text-red-800 dark:bg-red-950/50 dark:text-red-300';
            if (st === 'excused') return 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200';
            return 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300';
        },
        async loadPrograms() {
            this.loadingPrograms = true;
            try {
                const r = await fetch('<?= e($apiPrograms) ?>?action=list', { credentials: 'same-origin' });
                const j = await r.json();
                this.programs = (j.success && j.programs) ? j.programs : [];
            } finally {
                this.loadingPrograms = false;
            }
        },
        async onProgramChange() {
            this.sessions = [];
            this.roster = null;
            this.selectedSessionId = '';
            if (!this.selectedProgramId) return;
            this.loadingSessions = true;
            this.message = '';
            try {
                const from = new Date();
                from.setMonth(from.getMonth() - 3);
                const to = new Date();
                to.setMonth(to.getMonth() + 9);
                const qs = new URLSearchParams({
                    action: 'sessions',
                    program_id: this.selectedProgramId,
                    from: from.toISOString().slice(0, 10),
                    to: to.toISOString().slice(0, 10),
                });
                const r = await fetch('<?= e($apiPrograms) ?>?' + qs.toString(), { credentials: 'same-origin' });
                const j = await r.json();
                this.sessions = (j.success && j.sessions) ? j.sessions : [];
                if (this.sessions.length === 0) {
                    this.message = 'No sessions in this date range. Generate sessions from the program editor if needed.';
                }
            } finally {
                this.loadingSessions = false;
            }
        },
        async loadRoster() {
            this.roster = null;
            this.message = '';
            if (!this.selectedSessionId) return;
            const r = await fetch('<?= e($apiPrograms) ?>?action=attendance_roster&session_id=' + encodeURIComponent(this.selectedSessionId), {
                credentials: 'same-origin',
            });
            const j = await r.json();
            if (j.success) {
                this.roster = { session: j.session, registrants: j.registrants || [] };
            } else {
                this.message = j.message || 'Could not load roster';
            }
        },
        async setStatus(userId, status) {
            if (!this.selectedSessionId) return;
            this.savingUser = userId;
            this.message = '';
            try {
                const r = await fetch('<?= e($apiPrograms) ?>?action=attendance', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: '<?= e($csrfToken) ?>',
                        session_id: parseInt(this.selectedSessionId, 10),
                        user_id: userId,
                        status: status,
                    }),
                });
                const j = await r.json();
                if (j.success) {
                    const rows = this.roster.registrants;
                    const i = rows.findIndex(x => Number(x.user_id) === Number(userId));
                    if (i >= 0) {
                        rows[i].attendance_status = status;
                    }
                } else {
                    this.message = j.message || 'Update failed';
                }
            } finally {
                this.savingUser = null;
            }
        },
    };
}
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
