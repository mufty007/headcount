<?php

/**
 * Admin single program hub — overview, registrants, sessions/attendance, share.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Utilities;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;
use Headcount\Services\ProgramService;

AuthMiddleware::requireAdminOrCoordinator();

$organizationId = AuthMiddleware::getOrganizationId();
$config = require __DIR__ . '/../../config/config.php';
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
    $pageHeaderTitle = e($program['title'] ?? 'Program');
    $pageHeaderSubtitle = 'Manage registrants, session attendance, and sharing.';
    ob_start(); ?>
    <a href="<?= e($adminBase . '/index.php?page=programs') ?>" class="page-header-btn-secondary whitespace-nowrap flex-shrink-0">Back to Programs</a>
    <a href="<?= e($adminBase . '/index.php?page=program-edit&id=' . $programId) ?>" class="page-header-btn-primary whitespace-nowrap flex-shrink-0">Edit program</a>
    <?php $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php'; ?>

    <nav class="flex flex-wrap gap-2 mb-6 border-b border-gray-200 pb-3" aria-label="Program sections">
        <button type="button" @click="activeTab = 'overview'"
                :class="activeTab === 'overview' ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-gray-50 text-gray-600 border-transparent hover:bg-gray-100'"
                class="px-4 py-2 rounded-xl text-sm font-bold border transition-colors">Overview</button>
        <button type="button" @click="activeTab = 'registrants'; loadRegistrants()"
                :class="activeTab === 'registrants' ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-gray-50 text-gray-600 border-transparent hover:bg-gray-100'"
                class="px-4 py-2 rounded-xl text-sm font-bold border transition-colors">Registrants</button>
        <button type="button" @click="activeTab = 'sessions'; loadSessions()"
                :class="activeTab === 'sessions' ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-gray-50 text-gray-600 border-transparent hover:bg-gray-100'"
                class="px-4 py-2 rounded-xl text-sm font-bold border transition-colors">Sessions &amp; attendance</button>
        <button type="button" @click="activeTab = 'share'"
                :class="activeTab === 'share' ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-gray-50 text-gray-600 border-transparent hover:bg-gray-100'"
                class="px-4 py-2 rounded-xl text-sm font-bold border transition-colors">Share</button>
    </nav>

    <!-- Overview -->
    <div x-show="activeTab === 'overview'" x-cloak class="space-y-6">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-card">
                <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Status</div>
                <div class="text-lg font-black text-gray-900 capitalize"><?= e($program['status'] ?? 'draft') ?></div>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-card">
                <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Pricing</div>
                <div class="text-lg font-black text-gray-900">
                    <?php
                    $pt = $program['pricing_type'] ?? 'free';
                    if ($pt === 'free') {
                        echo 'Free';
                    } elseif ($pt === 'recurring') {
                        echo 'Recurring';
                    } else {
                        echo '$' . number_format((float) ($program['price_amount'] ?? 0), 2);
                    }
                    ?>
                </div>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-card">
                <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Registrants</div>
                <div class="text-lg font-black text-gray-900" x-text="registrants.length + ''">—</div>
            </div>
            <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-card">
                <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Upcoming sessions</div>
                <div class="text-lg font-black text-gray-900" x-text="sessions.length + ''">—</div>
            </div>
        </div>

        <div class="bento-card p-6 space-y-4">
            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider">Program details</h3>
            <?php if (!empty($program['category_name'])): ?>
            <p class="text-sm text-gray-600"><span class="font-semibold text-gray-800">Category:</span> <?= e($program['category_name']) ?></p>
            <?php endif; ?>
            <?php if (!empty($program['location'])): ?>
            <p class="text-sm text-gray-600"><span class="font-semibold text-gray-800">Location:</span> <?= e($program['location']) ?></p>
            <?php endif; ?>
            <?php if (!empty($program['session_start_time'])): ?>
            <p class="text-sm text-gray-600"><span class="font-semibold text-gray-800">Schedule:</span>
                <?= e(substr((string) $program['session_start_time'], 0, 5)) ?>
                <?php if (!empty($program['session_end_time'])): ?>
                    – <?= e(substr((string) $program['session_end_time'], 0, 5)) ?>
                <?php endif; ?>
            </p>
            <?php endif; ?>
            <?php if (!empty($program['description'])): ?>
            <div class="text-sm text-gray-600 prose max-w-none"><?= nl2br(e(Utilities::decodeHtmlEntities(strip_tags((string) $program['description'])))) ?></div>
            <?php endif; ?>
            <div class="flex flex-wrap gap-2 pt-2">
                <a href="<?= e($programShareUrl) ?>" target="_blank" rel="noopener" class="btn-secondary text-sm">Open portal page</a>
                <a href="<?= e($adminBase . '/index.php?page=program-edit&id=' . $programId) ?>" class="btn-primary text-sm">Edit settings</a>
            </div>
        </div>
    </div>

    <!-- Registrants -->
    <div x-show="activeTab === 'registrants'" x-cloak>
        <div class="ta-table-wrap">
            <div class="flex items-center justify-between border-b border-gray-100 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">Active registrants</h2>
                <span class="text-xs text-gray-500" x-show="loadingRegistrants">Loading…</span>
            </div>
            <table class="ta-table" x-show="registrants.length > 0">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Joined</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="r in registrants" :key="r.user_id || r.id">
                        <tr>
                            <td data-label="Name"><span class="font-medium" x-text="(r.first_name || '') + ' ' + (r.last_name || '')"></span></td>
                            <td data-label="Email"><span class="text-sm text-gray-600" x-text="r.email || '—'"></span></td>
                            <td data-label="Joined"><span class="text-sm text-gray-500" x-text="r.joined_at ? r.joined_at.slice(0, 10) : '—'"></span></td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <div class="px-6 py-10 text-center text-sm text-gray-500" x-show="!loadingRegistrants && registrants.length === 0">No active registrants yet.</div>
        </div>
    </div>

    <!-- Sessions & attendance -->
    <div x-show="activeTab === 'sessions'" x-cloak class="space-y-4">
        <div class="rounded-2xl border border-gray-200 bg-white p-4 shadow-card">
            <label class="mb-1.5 block text-sm font-semibold text-gray-700">Session</label>
            <select x-model="selectedSessionId" @change="loadRoster()" class="w-full max-w-md rounded-xl border border-gray-200 px-3 py-2.5 text-sm">
                <option value="">— Select a session —</option>
                <template x-for="s in sessions" :key="s.id">
                    <option :value="String(s.id)" x-text="sessionLabel(s)"></option>
                </template>
            </select>
            <p class="mt-2 text-xs text-amber-700" x-show="!loadingSessions && sessions.length === 0">No sessions found. Generate sessions from the program editor.</p>
        </div>

        <div class="ta-table-wrap" x-show="selectedSessionId && roster">
            <div class="border-b border-gray-100 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900" x-text="roster?.session ? (roster.session.program_title + ' — ' + roster.session.session_date) : ''"></h2>
            </div>
            <table class="ta-table">
                <thead>
                    <tr>
                        <th>Member</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th class="text-right">Mark as</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in (roster?.registrants || [])" :key="row.user_id">
                        <tr>
                            <td data-label="Member"><span class="font-medium" x-text="(row.first_name || '') + ' ' + (row.last_name || '')"></span></td>
                            <td data-label="Email"><span class="text-sm text-gray-600" x-text="row.email || '—'"></span></td>
                            <td data-label="Status">
                                <span class="inline-flex px-2.5 py-0.5 rounded-full text-xs font-semibold" :class="statusClass(row.attendance_status)" x-text="statusLabel(row.attendance_status)"></span>
                            </td>
                            <td data-label="Actions" class="text-right">
                                <div class="flex justify-end gap-1.5 flex-wrap">
                                    <button type="button" @click="setStatus(row.user_id, 'present')" :disabled="savingUser === row.user_id" class="rounded-lg border border-green-200 bg-green-50 px-2.5 py-1 text-xs font-semibold text-green-700">Present</button>
                                    <button type="button" @click="setStatus(row.user_id, 'absent')" :disabled="savingUser === row.user_id" class="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700">Absent</button>
                                    <button type="button" @click="setStatus(row.user_id, 'excused')" :disabled="savingUser === row.user_id" class="rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700">Excused</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <div class="px-6 py-10 text-center text-sm text-gray-500" x-show="roster && roster.registrants && roster.registrants.length === 0">No active registrants for this session.</div>
        </div>
    </div>

    <!-- Share -->
    <div x-show="activeTab === 'share'" x-cloak>
        <div class="bento-card p-6">
            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-4">Share program</h3>
            <p class="text-xs text-gray-500 mb-4">Scan or download the QR code to share the member portal program page.</p>
            <div class="flex flex-col sm:flex-row gap-6 items-start">
                <div class="shrink-0 rounded-xl border border-gray-200 bg-white p-2 shadow-card">
                    <img src="<?= e($programShareQrSrc) ?>" width="200" height="200" alt="QR code for program" class="w-[200px] h-[200px] object-contain">
                </div>
                <div class="flex-1 min-w-0 space-y-3 w-full">
                    <div>
                        <div class="text-[10px] font-bold text-gray-400 uppercase tracking-wider mb-1">Portal link</div>
                        <div class="break-all rounded-xl border border-gray-200 bg-gray-50 px-3 py-2 font-mono text-sm text-gray-800"><?= e($programShareUrl) ?></div>
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
