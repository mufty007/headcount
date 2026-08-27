<?php
/**
 * Create / edit program
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
use Headcount\Services\ProgramRequestService;
use Headcount\Services\ProgramService;

AuthMiddleware::requireAdminOrCoordinator();
$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();

$db = Database::getInstance();
$userData = $db->queryOne("SELECT first_name, last_name, email, role FROM users WHERE id = :id", ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email'],
    'role' => $userData['role'] ?? 'admin',
] : ['name' => 'Admin', 'email' => '', 'role' => 'admin'];

$editId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$canManagePrograms = AuthMiddleware::can('programs.manage');
$fromApprovedRequest = false;
if ($editId > 0) {
    try {
        $programRequestService = new ProgramRequestService();
        if ($programRequestService->tablesExist()) {
            $fromApprovedRequest = $programRequestService->userCanCompleteRequestProgram($organizationId, $userId, $editId);
        }
    } catch (\Throwable $e) {
        error_log('program-edit.php program request lookup: ' . $e->getMessage());
    }
}
if (!$canManagePrograms && !($editId > 0 && $fromApprovedRequest)) {
    http_response_code(403);
    echo 'Access denied. You can only edit a draft program created from your approved request, or you need the Manage programs permission.';
    exit;
}
$config = require HC_PROJECT_ROOT . '/config/config.php';

if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
$adminBase = $basePath . '/admin';
$apiPrograms = $basePath . '/public/api/programs.php';
$csrfToken = CsrfMiddleware::getToken();

$svc = new ProgramService();
$tableOk = $svc->tableExists('programs');
$categories = $tableOk ? $svc->listCategories($organizationId) : [];
$presenterUsers = $tableOk ? $svc->listPresenterUsers($organizationId) : [];
// Use SELECT * so missing city/country columns (before migration 046) do not cause SQL errors
$orgPrayerRow = $db->queryOne('SELECT * FROM organizations WHERE id = ?', [$organizationId]);
if (!is_array($orgPrayerRow)) {
    $orgPrayerRow = [];
}
$orgPrayer = [
    'city' => isset($orgPrayerRow['city']) ? (string) $orgPrayerRow['city'] : '',
    'country' => isset($orgPrayerRow['country']) ? (string) $orgPrayerRow['country'] : '',
    'timezone' => isset($orgPrayerRow['timezone']) ? (string) $orgPrayerRow['timezone'] : '',
];

$pageTitle = $editId ? 'Edit program' : 'New program';
$currentPage = 'programs';
$adminMainFullWidth = true;
require __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in admin-event-wizard w-full min-w-0" style="width:100%;max-width:100%" x-data="programEditApp()" x-init="init()">
    <?php
    ob_start();
    ?>
    <div class="flex items-center gap-3" x-show="form.id" x-cloak>
        <a :href="'<?= e($adminBase) ?>/index.php?page=program-details&id=' + form.id" class="btn-secondary text-sm py-2">Manage program</a>
        <a :href="'<?= e($adminBase) ?>/index.php?page=program-attendance&program_id=' + form.id" class="btn-secondary text-sm py-2">Attendance</a>
    </div>
    <?php
    $pageHeaderActions = ob_get_clean();
    $pageHeaderTitle = $editId ? 'Edit Program' : 'New Program';
    $pageHeaderBreadcrumb = [
        ['label' => 'Programs', 'url' => $adminBase . '/index.php?page=programs'],
        ['label' => $editId ? 'Edit Program' : 'New Program'],
    ];
    require __DIR__ . '/components/page-header.php';
    ?>

    <?php if (!$tableOk): ?>
        <div class="ta-alert ta-alert-warning flex-col items-start">
            <p class="font-semibold">Programs tables are not installed yet.</p>
            <p class="text-sm mt-2">Run the migration <code class="bg-amber-100 dark:bg-amber-900/30 px-1 rounded font-mono">039_programs_domain.sql</code> first.</p>
        </div>
    <?php else: ?>

    <!-- Step Progress -->
    <div class="multi-step-progress mb-8">
        <div class="step-item active" id="prog-step-item-1">
            <div class="step-circle">1</div>
            <span class="step-label">Basic Info</span>
        </div>
        <div class="step-item" id="prog-step-item-2">
            <div class="step-circle">2</div>
            <span class="step-label">Schedule</span>
        </div>
        <div class="step-item" id="prog-step-item-3">
            <div class="step-circle">3</div>
            <span class="step-label">Presenters</span>
        </div>
        <div class="step-item" id="prog-step-item-4">
            <div class="step-circle">4</div>
            <span class="step-label">Questions</span>
        </div>
    </div>

    <form @submit.prevent="save" id="program-edit-form">
        <!-- Status message -->
        <p class="text-sm text-brand-600 font-medium mb-4" x-show="message" x-text="message"></p>

        <!-- Step 1: Basic Info -->
        <div class="step-panel active" id="prog-panel-1">
            <?php ob_start(); ?>
            <div class="mb-4">
                <label class="form-label">Title <span class="text-red-500">*</span></label>
                <input type="text" x-model="form.title" required
                       class="ta-input w-full"
                       placeholder="e.g. Weekend Islamic Studies">
            </div>

            <div class="mb-6 w-full">
                <label class="form-label">Description</label>
                <div class="mt-1">
                    <textarea id="program-description" x-model="form.description" rows="4"
                              class="wysiwyg-editor ta-input w-full"
                              placeholder="What is this program about?"></textarea>
                </div>
            </div>

            <div class="mb-4 w-full">
                <label class="form-toggle cursor-pointer">
                    <input type="checkbox" x-model="form.is_virtual" :true-value="1" :false-value="0">
                    <div>
                        <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Virtual / online only</span>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Use when there is no physical meeting place.</p>
                    </div>
                </label>
            </div>

            <div class="mb-4 w-full" x-show="!form.is_virtual">
                <label class="form-label">Location</label>
                <p class="form-hint mb-2">Address, building, or room name shown on the public program page.</p>
                <input type="text" x-model="form.location"
                       class="ta-input w-full"
                       placeholder="e.g. Main masjid hall or 123 Community Dr">
            </div>
            <?php
            $facilityOptions = [];
            try {
                $facSvc = new \Headcount\Services\FacilityService();
                if ($facSvc->tableExists()) {
                    $facilityOptions = $facSvc->listForOrg($organizationId, ['status' => 'active']);
                }
            } catch (\Throwable $e) {
                $facilityOptions = [];
            }
            if (!empty($facilityOptions)): ?>
            <div class="mb-4 w-full" x-show="!form.is_virtual">
                <span class="form-label">Link to facilities (optional)</span>
                <p class="form-hint mb-2">Published program sessions block these rooms at session times.</p>
                <div class="space-y-2 rounded-xl border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-800">
                    <?php foreach ($facilityOptions as $fac): $fid = (int) ($fac['id'] ?? 0); if ($fid <= 0) continue; ?>
                    <label class="flex items-start gap-3 text-sm text-gray-700 dark:text-gray-200">
                        <input type="checkbox" value="<?= $fid ?>"
                               :checked="form.facility_ids.includes(<?= $fid ?>)"
                               @change="toggleFacility(<?= $fid ?>, $event.target.checked)"
                               class="mt-0.5 rounded border-gray-300 text-brand-600 focus:ring-brand-500">
                        <span><?= e($fac['name'] ?? 'Facility') ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="mb-4 w-full">
                <label class="form-label">Banner Image</label>
                <p class="form-hint mb-2">JPEG, PNG, GIF, or WebP. Max 5 MB.</p>
                <input type="file" id="program-banner-file" accept="image/jpeg,image/png,image/gif,image/webp" @change="handleBannerChange"
                       class="block w-full text-sm border border-gray-200 rounded-xl px-4 py-2.5 file:mr-4 file:py-1.5 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 dark:border-gray-700">
                <div class="mt-3 flex flex-wrap items-start gap-4" x-show="bannerDisplayUrl">
                    <div class="relative rounded-xl overflow-hidden border border-gray-200 bg-gray-100 max-w-md dark:bg-gray-800 dark:border-gray-700">
                        <img :src="bannerDisplayUrl" alt="" class="max-h-40 w-auto object-contain">
                    </div>
                    <button type="button" @click="removeBannerImage" class="text-sm text-red-600 hover:text-red-800 font-medium">Remove image</button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 mb-4">
                <div>
                    <label class="form-label">Status</label>
                    <select x-model="form.status"
                            class="ta-input w-full">
                        <option value="draft">Draft</option>
                        <option value="published">Published</option>
                        <option value="cancelled">Cancelled</option>
                        <option value="archived">Archived</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Program category</label>
                    <select x-model="form.category_id"
                            class="ta-input w-full">
                        <option value="">— None —</option>
                        <?php foreach ($categories as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"><?= e($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Basic Information';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-primary" onclick="progShowStep(2)">
                    Next: Schedule
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
                <a href="<?= e($adminBase . '/index.php?page=programs') ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>

        <!-- Step 2: Schedule & Pricing -->
        <div class="step-panel" id="prog-panel-2">
            <?php ob_start(); ?>
            <p class="text-sm text-gray-600 mb-4 dark:text-gray-300">To set the <strong class="font-semibold text-gray-800 dark:text-gray-100">banner image</strong> or <strong class="font-semibold text-gray-800 dark:text-gray-100">location</strong>, use <button type="button" class="text-brand-600 underline font-medium hover:text-brand-800" onclick="progShowStep(1)">Step 1: Basic Info</button>.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="form-label">Pricing Type</label>
                    <select x-model="form.pricing_type"
                            class="ta-input w-full">
                        <option value="free">Free</option>
                        <option value="one_time">One-Time</option>
                        <option value="recurring">Recurring</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Price (USD)</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 font-semibold text-sm">$</span>
                        <input type="number" step="0.01" x-model="form.price_amount"
                               class="w-full border border-gray-200 rounded-xl pl-8 pr-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-brand-500 transition-all dark:border-gray-700">
                    </div>
                </div>
            </div>

            <div class="mb-4" x-show="form.pricing_type === 'recurring'">
                <label class="form-label">Billing Interval</label>
                <select x-model="form.billing_interval"
                        class="ta-input w-full">
                    <option value="once">One-time</option>
                    <option value="week">Weekly</option>
                    <option value="week_2">Bi-weekly</option>
                    <option value="month">Monthly</option>
                </select>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="form-label">Session Recurrence</label>
                    <select x-model="form.recurrence_type"
                            class="ta-input w-full">
                        <option value="none">None (one-time)</option>
                        <option value="weekly">Weekly</option>
                        <option value="biweekly">Bi-weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>
                <div>
                    <label class="form-label">Capacity</label>
                    <input type="number" x-model="form.capacity" placeholder="Unlimited if empty"
                           class="ta-input w-full">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="form-label">Starts On</label>
                    <input type="date" x-model="form.starts_on"
                           class="ta-input w-full">
                </div>
                <div>
                    <label class="form-label">Ends On <span class="text-gray-400 font-normal text-xs">(optional)</span></label>
                    <input type="date" x-model="form.ends_on"
                           class="ta-input w-full">
                </div>
            </div>

            <div class="mb-4" x-show="form.recurrence_type === 'weekly' || form.recurrence_type === 'biweekly'">
                <label class="form-label">Days of week</label>
                <p class="text-xs text-gray-500 mb-2 dark:text-gray-400">Pick which weekdays sessions run, then set start and end times below.</p>
                <div class="flex flex-wrap gap-2">
                    <template x-for="day in sessionDayChips" :key="day.v">
                        <button type="button"
                                @click="toggleSessionDay(day.v)"
                                :class="sessionDaySelected(day.v) ? 'bg-brand-600 text-white border-brand-600' : 'bg-white text-gray-700 border-gray-200 hover:border-brand-300'"
                                class="px-3 py-2 rounded-xl text-sm font-medium border transition-colors"
                                x-text="day.label"></button>
                    </template>
                </div>
            </div>

            <div class="mb-4 p-4 rounded-xl border border-brand-100 bg-brand-50/40">
                <p class="text-sm font-semibold text-gray-900 mb-1 dark:text-white">Session start time</p>
                <p class="text-xs text-gray-600 mb-3 dark:text-gray-300">Timezone: <strong><?= e($orgPrayer['timezone'] ?? '—') ?></strong>. Prayer-based times use <a href="<?= e($adminBase . '/index.php?page=settings') ?>" class="text-brand-600 underline hover:text-brand-800">city &amp; country in Settings</a> with the <a href="https://aladhan.com/prayer-times-api" target="_blank" rel="noopener noreferrer" class="text-brand-600 underline">Aladhan API</a>.</p>
                <div class="mb-3">
                    <label class="form-label">Mode</label>
                    <select x-model="form.session_time_mode"
                            class="ta-input w-full">
                        <option value="clock">Fixed clock time</option>
                        <option value="after_prayer">Minutes after a prayer (salāh)</option>
                    </select>
                </div>
                <div x-show="form.session_time_mode === 'after_prayer'" class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-3">
                    <div>
                        <label class="form-label">Prayer</label>
                        <select x-model="form.prayer_name"
                                class="ta-input w-full">
                            <option value="">Select…</option>
                            <option value="Fajr">Fajr</option>
                            <option value="Sunrise">Sunrise</option>
                            <option value="Dhuhr">Dhuhr</option>
                            <option value="Asr">Asr</option>
                            <option value="Maghrib">Maghrib</option>
                            <option value="Isha">Isha</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Minutes after</label>
                        <input type="number" min="0" max="600" x-model.number="form.prayer_offset"
                               class="ta-input w-full">
                    </div>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400" x-show="form.session_time_mode === 'after_prayer'">
                    Generated sessions will use each day’s prayer time for your city plus this offset. Ensure city and country are set in Settings.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4" x-show="form.session_time_mode === 'clock'">
                <div>
                    <label class="form-label">Session Start Time</label>
                    <input type="time" x-model="form.session_start_time"
                           class="ta-input w-full">
                </div>
                <div>
                    <label class="form-label">Session End Time</label>
                    <input type="time" x-model="form.session_end_time"
                           class="ta-input w-full">
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4" x-show="form.session_time_mode === 'after_prayer'">
                <div>
                    <label class="form-label">Session End Time <span class="text-gray-400 font-normal text-xs">(fallback if prayer end disabled)</span></label>
                    <input type="time" x-model="form.session_end_time"
                           class="ta-input w-full" x-show="form.session_end_time_mode === 'clock'">
                </div>
            </div>

            <div class="mb-4 p-4 rounded-xl border border-gray-200 bg-gray-50/60 dark:bg-gray-800/40 dark:border-gray-700">
                <p class="text-sm font-semibold text-gray-900 mb-1 dark:text-white">Session end time</p>
                <p class="text-xs text-gray-500 mb-3 dark:text-gray-400">Use daily Maghrib (or another prayer) as the end time, or keep a fixed clock time.</p>
                <div class="mb-3">
                    <label class="form-label">End time mode</label>
                    <select x-model="form.session_end_time_mode" class="ta-input w-full">
                        <option value="clock">Fixed clock time</option>
                        <option value="prayer">At prayer time (e.g. Maghrib)</option>
                    </select>
                </div>
                <div x-show="form.session_end_time_mode === 'prayer'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">End prayer</label>
                        <select x-model="form.session_end_prayer_name" class="ta-input w-full">
                            <option value="Fajr">Fajr</option>
                            <option value="Sunrise">Sunrise</option>
                            <option value="Dhuhr">Dhuhr</option>
                            <option value="Asr">Asr</option>
                            <option value="Maghrib">Maghrib</option>
                            <option value="Isha">Isha</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">Minutes after prayer</label>
                        <input type="number" min="0" max="600" x-model.number="form.session_end_prayer_offset" class="ta-input w-full">
                    </div>
                </div>
                <div x-show="form.session_end_time_mode === 'clock'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="form-label">Session end time</label>
                        <input type="time" x-model="form.session_end_time" class="ta-input w-full">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="form-label">Daily break start <span class="text-gray-400 font-normal text-xs">(optional)</span></label>
                    <input type="time" x-model="form.break_start_time" class="ta-input w-full" placeholder="18:00">
                </div>
                <div>
                    <label class="form-label">Daily break end <span class="text-gray-400 font-normal text-xs">(optional)</span></label>
                    <input type="time" x-model="form.break_end_time" class="ta-input w-full" placeholder="18:30">
                </div>
            </div>
            <p class="text-xs text-gray-500 mb-4 dark:text-gray-400">Break window is copied to each generated session (e.g. 6:00–6:30 PM).</p>

            <div class="mb-4 p-4 rounded-xl border border-gray-200 bg-white dark:bg-gray-800 dark:border-gray-700">
                <label class="form-label">Registration mode</label>
                <select x-model="form.registration_mode" class="ta-input w-full mb-3">
                    <option value="whole_program">Whole program (default)</option>
                    <option value="select_weeks">Select weeks — members pick which weeks to enroll in</option>
                </select>

                <div x-show="form.registration_mode === 'select_weeks'" class="space-y-4">
                    <div>
                        <label class="form-label">Bundle price when all weeks selected</label>
                        <input type="number" step="0.01" min="0" x-model="form.bundle_all_weeks_price" class="ta-input w-full max-w-xs" placeholder="e.g. 50.00">
                        <p class="text-xs text-gray-500 mt-1 dark:text-gray-400">Leave empty to always charge the sum of selected week prices.</p>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <div class="text-sm font-semibold text-gray-800 dark:text-gray-100">Program weeks</div>
                            <p class="text-xs text-gray-500 dark:text-gray-400">Each week has its own price and session dates.</p>
                        </div>
                        <button type="button" @click="addWeek()" class="text-xs font-bold text-brand-600 hover:text-brand-800 shrink-0">+ Add week</button>
                    </div>
                    <template x-for="(wk, wIdx) in weeks" :key="wIdx">
                        <div class="rounded-xl border border-gray-200 p-4 space-y-3 dark:border-gray-600 dark:bg-gray-900">
                            <div class="flex flex-wrap gap-2 items-center">
                                <span class="text-xs font-bold text-gray-500" x-text="'Week ' + (wIdx + 1)"></span>
                                <button type="button" @click="moveWeek(wIdx, -1)" class="text-xs text-gray-500" title="Move up">↑</button>
                                <button type="button" @click="moveWeek(wIdx, 1)" class="text-xs text-gray-500" title="Move down">↓</button>
                                <button type="button" @click="removeWeek(wIdx)" class="ml-auto text-rose-600 text-sm font-bold">×</button>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1 dark:text-gray-400">Title</label>
                                    <input type="text" x-model="wk.title" placeholder="Week 2 — Lamiyyah" class="ta-input w-full">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1 dark:text-gray-400">Price ($)</label>
                                    <input type="number" step="0.01" min="0" x-model="wk.price_amount" class="ta-input w-full">
                                </div>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1 dark:text-gray-400">Description (optional)</label>
                                <textarea x-model="wk.description" rows="2" class="ta-input w-full" placeholder="Curriculum snippet"></textarea>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1 dark:text-gray-400">Capacity (optional)</label>
                                    <input type="number" min="1" x-model="wk.capacity" class="ta-input w-full">
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-gray-500 mb-1 dark:text-gray-400">Session dates (one YYYY-MM-DD per line)</label>
                                    <textarea x-model="wk.datesText" rows="3" class="ta-input w-full font-mono text-xs" placeholder="2026-07-11&#10;2026-07-12"></textarea>
                                </div>
                            </div>
                        </div>
                    </template>
                    <p class="text-sm text-gray-400 italic py-4 text-center rounded-xl border border-dashed border-gray-200 dark:border-gray-700" x-show="!weeks.length">No weeks yet. Add weeks for selective enrollment.</p>
                </div>
            </div>

            <label class="form-toggle cursor-pointer mb-4">
                <input type="checkbox" x-model="form.allow_guest_registration" :true-value="1" :false-value="0">
                <div>
                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Allow guest registration</span>
                    <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Anyone can browse this program publicly; guests can register without a portal account via the guest link</p>
                </div>
            </label>

            <label class="form-toggle cursor-pointer mb-4">
                <input type="checkbox" x-model="form.show_on_public_site" :true-value="1" :false-value="0">
                <div>
                    <span class="text-sm font-semibold text-gray-800 dark:text-gray-100">Show on Public Site</span>
                    <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Display this program on the public website</p>
                </div>
            </label>

            <?php if ($editId > 0):
                $programShareUrlEdit = headcount_program_portal_url($config, $editId);
                $programShareQrEdit = $basePath . '/public/api/program-share-qr.php?id=' . $editId;
                ?>
            <div class="border border-gray-200 rounded-xl p-4 mb-4 bg-white dark:bg-gray-800 dark:border-gray-700">
                <div class="text-sm font-semibold text-gray-800 mb-2 dark:text-gray-100">Share program</div>
                <div class="flex flex-col sm:flex-row gap-4 items-start">
                    <img src="<?= e($programShareQrEdit) ?>" width="120" height="120" alt="Program QR" class="w-[120px] h-[120px] object-contain border border-gray-100 rounded-lg dark:border-gray-800">
                    <div class="flex-1 min-w-0">
                        <p class="text-xs text-gray-500 break-all font-mono mb-2 dark:text-gray-400"><?= e($programShareUrlEdit) ?></p>
                        <p class="text-xs text-gray-500 break-all font-mono mb-2 dark:text-gray-400" x-show="form.allow_guest_registration == 1">
                            Guest link: <?= e(headcount_program_guest_register_url($config, $editId)) ?>
                        </p>
                        <a href="<?= e($adminBase . '/index.php?page=program-details&id=' . $editId) ?>" class="text-xs font-bold text-brand-600 hover:text-brand-800">Open program hub →</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Generate sessions (edit mode only) -->
            <div class="mb-2 rounded-xl border border-brand-100 bg-brand-50/80 p-4" x-show="form.id">
                <div class="mb-1 text-sm font-semibold text-brand-950">Generate Sessions</div>
                <p class="mb-3 text-xs text-brand-900/80">Create a 6-month schedule of sessions based on recurrence settings above.</p>
                <button type="button" @click="generateSessions" class="btn-primary text-sm py-2">Generate Sessions</button>
            </div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Schedule & Pricing';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-secondary" onclick="progShowStep(1)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back
                </button>
                <button type="button" class="btn-primary" onclick="progShowStep(3)">
                    Next: Presenters
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
                <a href="<?= e($adminBase . '/index.php?page=programs') ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>

        <!-- Step 3: Presenters -->
        <div class="step-panel" id="prog-panel-3">
            <?php ob_start(); ?>
            <p class="text-sm text-gray-500 mb-4 dark:text-gray-400">Add teachers, hosts, or speakers for this program. They appear on the member program page.</p>

            <div class="space-y-3 mb-4">
                <div class="flex items-center justify-between gap-2">
                    <div>
                        <div class="form-label mb-0">Presenters</div>
                        <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400">Optional — you can skip this step if none apply.</p>
                    </div>
                    <button type="button" @click="addPresenter()" class="text-xs font-bold text-brand-600 hover:text-brand-800 shrink-0">+ Add presenter</button>
                </div>
                <template x-for="(pr, pIdx) in presenters" :key="pIdx">
                    <div class="space-y-2 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-600 dark:bg-gray-900">
                        <div class="flex flex-wrap gap-2 items-center">
                            <button type="button" @click="movePresenter(pIdx, -1)" class="text-xs text-gray-500 hover:text-gray-800 dark:text-gray-100" title="Move up">↑</button>
                            <button type="button" @click="movePresenter(pIdx, 1)" class="text-xs text-gray-500 hover:text-gray-800 dark:text-gray-100" title="Move down">↓</button>
                            <button type="button" @click="removePresenter(pIdx)" class="ml-auto text-rose-600 text-sm font-bold" title="Remove">×</button>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1 dark:text-gray-400">Name</label>
                                <input type="text" x-model="pr.display_name" placeholder="e.g. Ustadh Ahmad" class="ta-input w-full">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-500 mb-1 dark:text-gray-400">Title (optional)</label>
                                <input type="text" x-model="pr.title" placeholder="e.g. Program director" class="ta-input w-full">
                            </div>
                        </div>
                        <?php if (!empty($presenterUsers)): ?>
                        <div>
                            <label class="block text-xs font-medium text-gray-500 mb-1 dark:text-gray-400">Login account (optional)</label>
                            <select x-model="pr.user_id" class="ta-input w-full">
                                <option value="">— Display only —</option>
                                <?php foreach ($presenterUsers as $pu): ?>
                                <option value="<?= (int) $pu['id'] ?>"><?= e(trim(($pu['first_name'] ?? '') . ' ' . ($pu['last_name'] ?? ''))) ?><?= !empty($pu['email']) ? ' (' . e($pu['email']) . ')' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="mt-1 text-xs text-gray-500">Link a presenter login so they can take attendance for this program.</p>
                        </div>
                        <?php endif; ?>
                        <div class="flex flex-wrap items-center gap-3 pt-1">
                            <template x-if="pr.image_path && !pr.remove_image">
                                <img :src="bannerDisplayUrlForPath(pr.image_path)" alt="" class="h-14 w-14 rounded-lg object-cover border border-gray-200 dark:border-gray-700">
                            </template>
                            <label class="text-xs text-gray-600 dark:text-gray-300">
                                <span class="block font-medium text-gray-700 mb-1 dark:text-gray-200">Photo</span>
                                <input type="file" accept="image/jpeg,image/png,image/gif,image/webp" class="block text-xs max-w-xs"
                                       @change="setPresenterImageFile(pIdx, $event)">
                            </label>
                            <label class="flex items-center gap-2 text-xs text-gray-600 cursor-pointer dark:text-gray-300" x-show="pr.image_path">
                                <input type="checkbox" x-model="pr.remove_image" class="rounded border-gray-300 text-brand-600">
                                Remove photo
                            </label>
                        </div>
                    </div>
                </template>
                <p class="text-sm text-gray-400 italic py-6 text-center rounded-xl border border-dashed border-gray-200 dark:border-gray-700" x-show="!presenters.length">No presenters yet. Click “Add presenter” to get started.</p>
            </div>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Presenters';
            require __DIR__ . '/components/form-section.php';
            ?>
            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-secondary" onclick="progShowStep(2)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back
                </button>
                <button type="button" class="btn-primary" onclick="progShowStep(4)">
                    Next: Questions
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                </button>
                <a href="<?= e($adminBase . '/index.php?page=programs') ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>

        <!-- Step 4: Questions & Save -->
        <div class="step-panel" id="prog-panel-4">
            <?php ob_start(); ?>
            <p class="text-sm text-gray-500 mb-4 dark:text-gray-400">Optional fields shown when members register. Saved with the program.</p>

            <div class="space-y-3 mb-4">
                <template x-for="(q, idx) in questions" :key="idx">
                    <div class="border border-gray-200 rounded-xl p-3 bg-gray-50 space-y-2 dark:bg-gray-800 dark:border-gray-700">
                        <div class="grid grid-cols-1 sm:grid-cols-[minmax(0,1fr)_10.5rem_auto_auto] gap-x-3 gap-y-2 items-end">
                            <div class="min-w-0">
                                <label class="block text-xs font-medium text-gray-500 mb-1 dark:text-gray-400">Question</label>
                                <input type="text" x-model="q.question_text"
                                       class="ta-input w-full"
                                       placeholder="e.g. Dietary restrictions">
                            </div>
                            <div class="w-full sm:w-[10.5rem]">
                                <label class="block text-xs font-medium text-gray-500 mb-1 dark:text-gray-400">Type</label>
                                <select x-model="q.question_type"
                                        class="ta-input w-full">
                                    <option value="short_text">Short text</option>
                                    <option value="text">Long text</option>
                                    <option value="number">Number</option>
                                    <option value="checkbox">Checkbox (yes/no)</option>
                                    <option value="radio">Radio (one choice)</option>
                                    <option value="dropdown">Dropdown</option>
                                    <option value="multi_checkbox">Multi-checkbox</option>
                                </select>
                            </div>
                            <label class="flex items-center gap-2 pb-2 sm:pb-2.5 whitespace-nowrap">
                                <input type="checkbox" x-model="q.is_required">
                                <span class="text-sm text-gray-700 dark:text-gray-200">Required</span>
                            </label>
                            <button type="button" @click="questions.splice(idx, 1)" class="text-sm text-red-600 hover:text-red-800 font-medium pb-2 sm:pb-2.5 justify-self-start sm:justify-self-end">Remove</button>
                        </div>
                        <div class="pl-2 border-l-2 border-brand-200 space-y-1.5" x-show="['radio','dropdown','multi_checkbox'].includes(q.question_type)">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-brand-700">Answer choices</span>
                                <button type="button" @click="q.options = q.options || []; q.options.push({ option_label: '', sort_order: q.options.length })" class="text-xs font-bold text-brand-600 hover:text-brand-800">+ Add option</button>
                            </div>
                            <template x-for="(opt, oi) in (q.options || [])" :key="oi">
                                <div class="flex items-center gap-2">
                                    <input type="text" x-model="opt.option_label" placeholder="Option label" class="flex-1 min-w-0 border border-gray-200 rounded-lg px-2 py-1.5 text-sm bg-white dark:bg-gray-800 dark:border-gray-700">
                                    <button type="button" @click="q.options.splice(oi, 1)" class="text-red-600 hover:text-red-800 text-xs font-bold shrink-0">Remove</button>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
            <button type="button" @click="addQuestion" class="text-sm text-brand-600 hover:text-brand-800 font-medium mb-6">+ Add question</button>
            <?php
            $formSectionContent = ob_get_clean();
            $formSectionTitle = 'Registration Questions';
            require __DIR__ . '/components/form-section.php';
            ?>

            <div class="form-sticky-footer step-nav">
                <button type="button" class="btn-secondary" onclick="progShowStep(3)">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path></svg>
                    Back
                </button>
                <button type="submit" :disabled="saving" class="btn-primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    <span x-text="saving ? 'Saving...' : (form.id ? 'Save Changes' : 'Create Program')"></span>
                </button>
                <a href="<?= e($adminBase . '/index.php?page=programs') ?>" class="btn-secondary">Cancel</a>
            </div>
        </div>
    </form>

    <!-- In-app dialog (replaces browser alert for consistent light UI) -->
    <div x-show="dialog.open"
         x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[100] flex items-center justify-center p-4 sm:p-6"
         role="dialog"
         aria-modal="true"
         aria-labelledby="program-dialog-title"
         @keydown.escape.window="dialog.open = false">
        <div class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="dialog.open = false" aria-hidden="true"></div>
        <div class="relative w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-card-lg sm:p-7 dark:bg-gray-800 dark:border-gray-700">
            <h2 id="program-dialog-title" class="text-lg font-semibold text-gray-900 dark:text-white" x-text="dialog.title"></h2>
            <p class="mt-3 text-sm text-gray-600 leading-relaxed whitespace-pre-wrap dark:text-gray-300" x-text="dialog.message"></p>
            <div class="mt-6 flex justify-end">
                <button type="button" @click="dialog.open = false"
                        class="px-5 py-2.5 rounded-xl bg-brand-600 text-white text-sm font-semibold hover:bg-brand-700 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2">
                    OK
                </button>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<script>
window.progShowStep = function(step) {
    var totalSteps = 4;
    step = Math.max(1, Math.min(totalSteps, step));
    for (var i = 1; i <= totalSteps; i++) {
        var panel = document.getElementById('prog-panel-' + i);
        var item = document.getElementById('prog-step-item-' + i);
        if (panel) panel.classList.toggle('active', i === step);
        if (item) {
            item.classList.remove('active', 'completed');
            if (i === step) item.classList.add('active');
            else if (i < step) item.classList.add('completed');
        }
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
};
</script>




<script>
function programEditApp() {
    return {
        saving: false,
        message: '',
        form: {
            id: <?= $editId ? (int)$editId : 'null' ?>,
            title: '',
            description: '',
            location: '',
            facility_ids: [],
            is_virtual: 0,
            banner_image: '',
            status: 'draft',
            category_id: '',
            pricing_type: 'free',
            price_amount: '',
            billing_interval: 'once',
            recurrence_type: 'weekly',
            capacity: '',
            starts_on: '',
            ends_on: '',
            session_start_time: '',
            session_end_time: '',
            session_days_of_week: [],
            show_on_public_site: 1,
            session_time_mode: 'clock',
            prayer_name: '',
            prayer_offset: 0,
            registration_mode: 'whole_program',
            bundle_all_weeks_price: '',
            allow_guest_registration: 1,
            session_end_time_mode: 'clock',
            session_end_prayer_name: 'Maghrib',
            session_end_prayer_offset: 0,
            break_start_time: '',
            break_end_time: '',
        },
        weeks: [],
        sessionDayChips: [
            { v: 0, label: 'Sun' }, { v: 1, label: 'Mon' }, { v: 2, label: 'Tue' },
            { v: 3, label: 'Wed' }, { v: 4, label: 'Thu' }, { v: 5, label: 'Fri' }, { v: 6, label: 'Sat' },
        ],
        bannerImageFile: null,
        bannerPreviewDataUrl: null,
        removeBanner: false,
        questions: [],
        presenters: [],
        /** @type {Record<string, File>} */
        presenterImageFiles: {},
        dialog: { open: false, title: 'Notice', message: '' },
        bannerDisplayUrlForPath(p) {
            if (!p) return '';
            const s = String(p);
            if (s.startsWith('http://') || s.startsWith('https://') || s.startsWith('data:')) return s;
            return '<?= e(rtrim($basePath, '/')) ?>/public/api/image.php?path=' + encodeURIComponent(s);
        },
        get bannerDisplayUrl() {
            if (this.removeBanner) return '';
            if (this.bannerPreviewDataUrl) return this.bannerPreviewDataUrl;
            const p = this.form.banner_image;
            if (!p) return '';
            if (typeof p === 'string' && (p.startsWith('http://') || p.startsWith('https://') || p.startsWith('data:'))) return p;
            return '<?= e(rtrim($basePath, '/')) ?>/public/api/image.php?path=' + encodeURIComponent(p);
        },
        showDialog(message, title) {
            this.dialog.message = message || '';
            this.dialog.title = title || 'Notice';
            this.dialog.open = true;
        },
        handleBannerChange(event) {
            const file = event.target.files && event.target.files[0];
            if (!file) return;
            if (!file.type.match(/^image\/(jpeg|png|gif|webp)$/i)) {
                this.showDialog('Please choose a JPEG, PNG, GIF, or WebP image.', 'Invalid image');
                event.target.value = '';
                return;
            }
            if (file.size > 5242880) {
                this.showDialog('Image must be 5 MB or smaller.', 'File too large');
                event.target.value = '';
                return;
            }
            this.bannerImageFile = file;
            this.removeBanner = false;
            const reader = new FileReader();
            reader.onload = (e) => { this.bannerPreviewDataUrl = e.target.result; };
            reader.readAsDataURL(file);
        },
        removeBannerImage() {
            this.form.banner_image = '';
            this.bannerImageFile = null;
            this.bannerPreviewDataUrl = null;
            this.removeBanner = true;
            const el = document.getElementById('program-banner-file');
            if (el) el.value = '';
        },
        addQuestion() {
            this.questions.push({
                question_text: '',
                question_type: 'short_text',
                is_required: false,
                options: [],
            });
        },
        addPresenter() {
            const n = this.presenters.length;
            this.presenters.push({
                display_name: '',
                title: '',
                image_path: '',
                user_id: '',
                sort_order: n,
                remove_image: false,
            });
        },
        removePresenter(index) {
            this.presenters.splice(index, 1);
            this.presenterImageFiles = {};
        },
        movePresenter(index, delta) {
            const arr = this.presenters;
            if (!arr || index < 0 || index >= arr.length) return;
            const j = index + delta;
            if (j < 0 || j >= arr.length) return;
            const t = arr[index];
            arr[index] = arr[j];
            arr[j] = t;
            this.presenterImageFiles = {};
        },
        addWeek() {
            this.weeks.push({ id: null, title: '', description: '', price_amount: '', capacity: '', datesText: '', session_dates: [] });
        },
        removeWeek(index) {
            this.weeks.splice(index, 1);
        },
        moveWeek(index, delta) {
            const j = index + delta;
            if (j < 0 || j >= this.weeks.length) return;
            const arr = [...this.weeks];
            const t = arr[index];
            arr[index] = arr[j];
            arr[j] = t;
            this.weeks = arr;
        },
        parseWeekDatesText(str) {
            return String(str || '').split(/\r?\n/).map(s => s.trim()).filter(Boolean);
        },
        setPresenterImageFile(index, evt) {
            const file = evt.target.files && evt.target.files[0];
            if (!file) return;
            if (!file.type.match(/^image\/(jpeg|png|gif|webp)$/i)) {
                this.showDialog('Please choose a JPEG, PNG, GIF, or WebP image.', 'Invalid image');
                evt.target.value = '';
                return;
            }
            if (file.size > 5242880) {
                this.showDialog('Image must be 5 MB or smaller.', 'File too large');
                evt.target.value = '';
                return;
            }
            this.presenterImageFiles[String(index)] = file;
            if (this.presenters[index]) {
                this.presenters[index].remove_image = false;
            }
        },
        toggleFacility(id, checked) {
            const n = parseInt(id, 10);
            let arr = Array.isArray(this.form.facility_ids) ? this.form.facility_ids.map(x => parseInt(x, 10)) : [];
            const i = arr.indexOf(n);
            if (checked && i < 0) arr.push(n);
            if (!checked && i >= 0) arr.splice(i, 1);
            this.form.facility_ids = arr;
        },
        toggleSessionDay(d) {
            const n = parseInt(d, 10);
            let arr = Array.isArray(this.form.session_days_of_week) ? [...this.form.session_days_of_week.map(x => parseInt(x, 10))] : [];
            const i = arr.indexOf(n);
            if (i >= 0) arr.splice(i, 1);
            else arr.push(n);
            arr.sort((a, b) => a - b);
            this.form.session_days_of_week = arr;
        },
        sessionDaySelected(d) {
            const n = parseInt(d, 10);
            const arr = this.form.session_days_of_week;
            if (!Array.isArray(arr)) return false;
            return arr.map(x => parseInt(x, 10)).indexOf(n) >= 0;
        },
        normalizeTimeInput(v) {
            if (v == null || v === '') return '';
            const s = String(v).trim();
            const m = s.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?/);
            if (!m) return '';
            const h = Math.min(23, parseInt(m[1], 10));
            const min = Math.min(59, parseInt(m[2], 10));
            return String(h).padStart(2, '0') + ':' + String(min).padStart(2, '0');
        },
        normalizeDateInput(v) {
            if (v == null || v === '') return '';
            const s = String(v).trim();
            const m = s.match(/^(\d{4}-\d{2}-\d{2})/);
            return m ? m[1] : s;
        },
        async init() {
            if (!this.form.id) return;
            const r = await fetch('<?= e($apiPrograms) ?>?action=get&id=' + this.form.id, { credentials: 'same-origin' });
            const j = await r.json();
            if (j.success && j.program) {
                const p = j.program;
                for (const k of Object.keys(this.form)) {
                    if (!Object.prototype.hasOwnProperty.call(p, k)) continue;
                    const val = p[k];
                    if (val === null || val === '') {
                        if (['session_start_time', 'session_end_time', 'ends_on', 'starts_on', 'location'].includes(k)) {
                            this.form[k] = '';
                        }
                        continue;
                    }
                    this.form[k] = val;
                }
                this.form.is_virtual = (p.is_virtual === 1 || p.is_virtual === true || p.is_virtual === '1') ? 1 : 0;
                this.form.facility_ids = Array.isArray(p.facility_ids) ? p.facility_ids.map(x => parseInt(x, 10)).filter(n => n > 0) : [];
                this.form.starts_on = this.normalizeDateInput(p.starts_on);
                this.form.ends_on = this.normalizeDateInput(p.ends_on);
                this.form.session_start_time = this.normalizeTimeInput(p.session_start_time);
                this.form.session_end_time = this.normalizeTimeInput(p.session_end_time);
                this.form.break_start_time = this.normalizeTimeInput(p.break_start_time);
                this.form.break_end_time = this.normalizeTimeInput(p.break_end_time);
                if (p.session_end_prayer_name) {
                    this.form.session_end_time_mode = 'prayer';
                    this.form.session_end_prayer_name = p.session_end_prayer_name;
                    this.form.session_end_prayer_offset = p.session_end_prayer_offset != null ? parseInt(p.session_end_prayer_offset, 10) : 0;
                } else {
                    this.form.session_end_time_mode = (p.session_end_time_mode === 'prayer') ? 'prayer' : 'clock';
                }
                if (Array.isArray(p.weeks)) {
                    this.weeks = p.weeks.map((w) => ({
                        id: w.id || null,
                        title: w.title || '',
                        description: w.description || '',
                        price_amount: w.price_amount != null && w.price_amount !== '' ? String(w.price_amount) : '',
                        capacity: w.capacity != null && w.capacity !== '' ? String(w.capacity) : '',
                        datesText: Array.isArray(w.session_dates) ? w.session_dates.join('\n') : '',
                        session_dates: Array.isArray(w.session_dates) ? w.session_dates : [],
                    }));
                } else {
                    this.weeks = [];
                }
                if (p.category_id) this.form.category_id = String(p.category_id);
                if (p.prayer_name) {
                    this.form.session_time_mode = 'after_prayer';
                    this.form.prayer_name = p.prayer_name;
                    this.form.prayer_offset = p.prayer_offset != null ? parseInt(p.prayer_offset, 10) : 0;
                } else {
                    this.form.session_time_mode = 'clock';
                    this.form.prayer_name = '';
                    this.form.prayer_offset = 0;
                }
                if (Array.isArray(p.session_days_of_week)) {
                    this.form.session_days_of_week = p.session_days_of_week.map(x => parseInt(x, 10));
                } else {
                    this.form.session_days_of_week = [];
                }
                if (Array.isArray(p.questions) && p.questions.length) {
                    this.questions = p.questions.map((q, i) => ({
                        id: q.id || null,
                        question_text: q.question_text || '',
                        question_type: q.question_type || 'short_text',
                        is_required: !!q.is_required,
                        sort_order: q.sort_order != null ? q.sort_order : i + 1,
                        options: Array.isArray(q.options) ? q.options.map((o, oi) => ({
                            id: o.id || null,
                            option_label: (o.option_label != null ? String(o.option_label) : '').trim() || '',
                            sort_order: o.sort_order != null ? o.sort_order : oi,
                        })) : [],
                    }));
                } else {
                    this.questions = [];
                }
                if (Array.isArray(p.presenters) && p.presenters.length) {
                    this.presenters = p.presenters.map((pr, i) => ({
                        display_name: pr.display_name || '',
                        title: pr.title || '',
                        image_path: pr.image_path || '',
                        user_id: pr.user_id ? String(pr.user_id) : '',
                        sort_order: pr.sort_order != null ? pr.sort_order : i,
                        remove_image: false,
                    }));
                } else {
                    this.presenters = [];
                }
                this.presenterImageFiles = {};
                
                // Update Quill if it exists
                const el = document.getElementById('program-description');
                if (el) { el.dispatchEvent(new Event('sync-to-quill')); }
            }
            
            // Initialize WYSIWYG for this specific field with Alpine sync
            if (window.initWYSIWYG) {
                window.initWYSIWYG('#program-description', {
                    syncToQuill: true,
                    onChange: (content) => {
                        this.form.description = content;
                    }
                });
            }
        },
        async save() {
            this.saving = true;
            this.message = '';
            const rt = this.form.recurrence_type;
            if (rt === 'weekly' || rt === 'biweekly') {
                const days = this.form.session_days_of_week;
                if (!Array.isArray(days) || days.length === 0) {
                    this.message = 'Select at least one day of the week.';
                    this.saving = false;
                    return;
                }
                const so = this.form.starts_on;
                if (so) {
                    const w = new Date(so + 'T12:00:00').getDay();
                    const set = days.map(x => parseInt(x, 10));
                    if (set.indexOf(w) < 0) {
                        this.message = 'Start date does not fall on one of the selected weekdays. Change the start date or the selected days.';
                        this.saving = false;
                        return;
                    }
                }
            }
            const payload = { ...this.form, csrf_token: '<?= e($csrfToken) ?>' };
            const sessionMode = payload.session_time_mode;
            if (sessionMode === 'after_prayer') {
                if (!payload.prayer_name) {
                    this.message = 'Select a prayer, or switch to fixed clock time.';
                    this.saving = false;
                    return;
                }
                payload.session_start_time = null;
            } else {
                payload.prayer_name = null;
                payload.prayer_offset = 0;
            }
            delete payload.session_time_mode;
            const normSendTime = (x) => {
                if (x == null || x === '') return null;
                const s = String(x).trim();
                const m = s.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?/);
                if (!m) return null;
                return String(Math.min(23, parseInt(m[1], 10))).padStart(2, '0') + ':' +
                    String(Math.min(59, parseInt(m[2], 10))).padStart(2, '0') + ':' +
                    String(m[3] != null ? Math.min(59, parseInt(m[3], 10)) : 0).padStart(2, '0');
            };
            if (sessionMode !== 'after_prayer') {
                payload.session_start_time = normSendTime(payload.session_start_time);
            }
            const endMode = payload.session_end_time_mode || 'clock';
            if (endMode === 'prayer') {
                payload.session_end_time = null;
                if (!payload.session_end_prayer_name) {
                    payload.session_end_prayer_name = 'Maghrib';
                }
            } else {
                payload.session_end_prayer_name = null;
                payload.session_end_prayer_offset = 0;
                payload.session_end_time = normSendTime(payload.session_end_time);
            }
            delete payload.session_end_time_mode;
            payload.break_start_time = normSendTime(payload.break_start_time);
            payload.break_end_time = normSendTime(payload.break_end_time);
            if (payload.registration_mode !== 'select_weeks') {
                payload.weeks = [];
            } else {
                payload.weeks = (this.weeks || [])
                    .map((w, i) => ({
                        id: w.id || null,
                        title: (w.title || '').trim(),
                        description: (w.description || '').trim(),
                        price_amount: w.price_amount === '' || w.price_amount == null ? 0 : parseFloat(w.price_amount),
                        capacity: w.capacity === '' || w.capacity == null ? null : parseInt(w.capacity, 10),
                        session_dates: this.parseWeekDatesText(w.datesText),
                        sort_order: i + 1,
                    }))
                    .filter((w) => w.title !== '');
            }
            if (payload.bundle_all_weeks_price === '') payload.bundle_all_weeks_price = null;
            if (payload.category_id === '') payload.category_id = null;
            if (rt !== 'weekly' && rt !== 'biweekly') {
                payload.session_days_of_week = null;
            } else if (Array.isArray(payload.session_days_of_week)) {
                payload.session_days_of_week = payload.session_days_of_week.map(x => parseInt(x, 10));
            }
            const qList = (this.questions || [])
                .map((q, i) => {
                    const rawOpts = Array.isArray(q.options) ? q.options : [];
                    const options = rawOpts
                        .map((o) => ({ option_label: (o.option_label || '').trim() }))
                        .filter((o) => o.option_label !== '');
                    return {
                        id: q.id || null,
                        question_text: (q.question_text || '').trim(),
                        question_type: q.question_type || 'short_text',
                        is_required: !!q.is_required,
                        sort_order: i + 1,
                        options,
                    };
                })
                .filter((q) => {
                    if (q.question_text === '') return false;
                    if (['radio', 'dropdown', 'multi_checkbox'].includes(q.question_type) && (!q.options || q.options.length === 0)) return false;
                    return true;
                });
            payload.questions = qList;
            payload.presenters = (this.presenters || [])
                .map((pr, idx) => ({
                    display_name: (pr.display_name || '').trim(),
                    title: (pr.title || '').trim(),
                    image_path: pr.remove_image ? '' : (pr.image_path || ''),
                    user_id: pr.user_id ? parseInt(pr.user_id, 10) : 0,
                    sort_order: pr.sort_order != null ? pr.sort_order : idx,
                    remove_image: !!pr.remove_image,
                }))
                .filter((pr) => pr.display_name !== '');
            if (this.removeBanner) {
                payload.remove_banner_image = true;
                payload.banner_image = null;
            }
            const fd = new FormData();
            fd.append('payload', JSON.stringify(payload));
            if (this.bannerImageFile) {
                fd.append('banner_image', this.bannerImageFile);
            }
            if (this.presenterImageFiles) {
                Object.keys(this.presenterImageFiles).forEach((k) => {
                    const f = this.presenterImageFiles[k];
                    if (f) {
                        fd.append('presenter_image_' + k, f);
                    }
                });
            }
            const r = await fetch('<?= e($apiPrograms) ?>?action=save', {
                method: 'POST',
                credentials: 'same-origin',
                body: fd,
            });
            const j = await r.json();
            this.saving = false;
            if (j.success) {
                this.message = 'Saved.';
                if (j.banner_image !== undefined && j.banner_image !== null) {
                    this.form.banner_image = j.banner_image;
                } else if (this.removeBanner) {
                    this.form.banner_image = '';
                }
                this.bannerImageFile = null;
                this.bannerPreviewDataUrl = null;
                this.removeBanner = false;
                this.presenterImageFiles = {};
                const fin = document.getElementById('program-banner-file');
                if (fin) fin.value = '';
                if (j.id) {
                    this.form.id = j.id;
                    const u = new URL(window.location.href);
                    u.searchParams.set('id', j.id);
                    window.history.replaceState({}, '', u);
                }
            } else {
                this.message = j.message || 'Save failed';
            }
        },
        async generateSessions() {
            if (!this.form.id) return;
            const rt = this.form.recurrence_type;
            if (rt === 'none') {
                this.showDialog('Set Session Recurrence to Weekly, Bi-weekly, or Monthly (not "None"), save, then try again.', 'Recurrence required');
                return;
            }
            if (!this.form.starts_on || String(this.form.starts_on).trim() === '') {
                this.showDialog('Set "Starts On" to the first session date, save the program, then generate sessions.', 'Start date required');
                return;
            }
            const so = String(this.form.starts_on).trim();
            const eo = String(this.form.ends_on || '').trim();
            if (eo !== '') {
                const sMs = new Date(so + 'T12:00:00').getTime();
                const eMs = new Date(eo + 'T12:00:00').getTime();
                if (!Number.isNaN(sMs) && !Number.isNaN(eMs) && eMs < sMs) {
                    this.showDialog('"Ends On" must be on or after "Starts On". Update the dates, save, then generate again.', 'Date range');
                    return;
                }
            }
            const r = await fetch('<?= e($apiPrograms) ?>?action=generate_sessions', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: '<?= e($csrfToken) ?>', program_id: this.form.id, horizon_months: 6 }),
            });
            const j = await r.json();
            if (j.message) {
                this.showDialog(j.message, j.success ? 'Notice' : 'Could not generate');
            } else if (j.success) {
                this.showDialog('Created ' + j.created + ' session(s).', 'Sessions generated');
            } else {
                this.showDialog('Could not generate sessions.', 'Error');
            }
        },
    };
}
</script>
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
