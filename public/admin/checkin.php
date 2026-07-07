<?php

/**
 * Admin Check-In Page
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
use Headcount\Helpers\Security;
use Headcount\Helpers\OrgTimeZone;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;

AuthMiddleware::requireAdminOrCoordinator();

// CRITICAL: Set Permissions-Policy FIRST to allow camera access
// This must be set before Security::setSecurityHeaders() to ensure it's not overridden
if (!headers_sent()) {
    // Set camera and microphone permissions for QR scanning
    // Using * allows camera from all origins (required for getUserMedia)
            header('Permissions-Policy: camera=self, microphone=self, geolocation=()', true);
}

// Set security headers (will detect existing Permissions-Policy and not override it)
Security::setSecurityHeaders();

$organizationId = AuthMiddleware::getOrganizationId();
$db = Database::getInstance();

// Calculate base URL
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
$basePath = preg_replace('#/admin/.*$#', '', $requestPath);
$basePath = rtrim($basePath, '/');
$baseUrl = $basePath;

// Calculate assets base path
if (strpos($basePath, '/public') !== false) {
    $assetsBase = $basePath . '/assets/';
} else {
    $assetsBase = $basePath . '/public/assets/';
}

$config = require HC_PROJECT_ROOT . '/config/config.php';
$appName = $config['app']['name'] ?? 'Headcount';
$appUrl = $config['app']['url'] ?? $baseUrl;

$eventId = $_GET['event_id'] ?? null;

if (!$eventId) {
    setFlash('error', 'No event selected for check-in.');
    Utilities::redirect($baseUrl . '/admin/?page=events');
}

// Get event details
$event = $db->queryOne("SELECT * FROM events WHERE id = :id AND organization_id = :org_id", [
    'id' => $eventId,
    'org_id' => $organizationId
]);

if (!$event) {
    setFlash('error', 'Event not found.');
    Utilities::redirect($baseUrl . '/admin/?page=events');
}

headcount_decode_html_entities_in_event_row($event);

$isPaidEvent = (isset($event['ticket_price']) && (float)$event['ticket_price'] > 0);
$canCorrectCheckins = AuthMiddleware::canCorrectCheckins();
$eventDateYmdCheckin = substr((string) ($event['event_date'] ?? ''), 0, 10);
$eventStartTimeCheckin = !empty($event['start_time']) ? substr((string) $event['start_time'], 0, 8) : null;

$hasEventQuestions = false;
try {
    $questionCountRow = $db->queryOne(
        'SELECT COUNT(*) AS c FROM event_questions WHERE event_id = :event_id',
        ['event_id' => $eventId]
    );
    $hasEventQuestions = ((int) ($questionCountRow['c'] ?? 0)) > 0;
} catch (\Exception $e) {
    $hasEventQuestions = false;
}

$orgTzRow = $db->queryOne("SELECT timezone FROM organizations WHERE id = :id", ['id' => $organizationId]);
$orgTimezone = OrgTimeZone::resolve(is_array($orgTzRow) ? ($orgTzRow['timezone'] ?? null) : null);

// Get check-in statistics (head count = registrants + guests on "yes" RSVPs)
try {
    $rsvpCols = $db->query('SHOW COLUMNS FROM rsvps');
    $rsvpNames = array_column($rsvpCols, 'Field');
    $guestCountCol = in_array('guest_count', $rsvpNames, true) ? ', r.guest_count' : '';
    $fmJoin = '';
    try {
        if ($db->hasColumn('attendance', 'family_member_id')) {
            $fmJoin = ' AND IFNULL(a.family_member_id, 0) = 0';
        }
    } catch (\Exception $e) {
        $fmJoin = '';
    }
    $aggRows = $db->query(
        "SELECT r.status as rsvp_status{$guestCountCol},
        CASE WHEN a.checked_in_at IS NOT NULL THEN 1 ELSE 0 END as checked_in
        FROM rsvps r
        LEFT JOIN attendance a ON a.event_id = r.event_id AND a.user_id = r.user_id
            AND DATE(a.checked_in_at) = :event_date{$fmJoin}
        WHERE r.event_id = :event_id",
        ['event_id' => $eventId, 'event_date' => $event['event_date']]
    );
    $headStats = headcount_checkin_head_stats_from_rsvp_rows($aggRows);
    $canonical = headcount_rsvp_yes_canonical_counts($db, (int) $eventId);
    $headStats = headcount_merge_canonical_rsvp_yes_headcounts($headStats, $canonical);
    $headsExpr = headcount_attendance_heads_sum_expr($db, 'a');
    $stats = $db->queryOne(
        "SELECT (SELECT {$headsExpr} FROM attendance a WHERE a.event_id = :event_id1 AND a.checked_in_at IS NOT NULL AND DATE(a.checked_in_at) = :event_date1) as checked_in",
        ['event_id1' => $eventId, 'event_date1' => $event['event_date']]
    );
    $stats['rsvp_yes'] = (int) ($headStats['total_registrants_yes'] ?? 0);
    $stats['total_heads'] = (int) ($headStats['total_heads'] ?? 0);
    $stats['not_checked_in_heads'] = (int) ($headStats['not_checked_in_heads'] ?? 0);
} catch (\Exception $e) {
    try {
        $headsExprFallback = headcount_attendance_heads_sum_expr($db, 'a');
        $checkedInCount = $db->queryOne(
            "SELECT {$headsExprFallback} as checked_in FROM attendance a WHERE a.event_id = :event_id AND a.checked_in_at IS NOT NULL AND DATE(a.checked_in_at) = :event_date",
            ['event_id' => $eventId, 'event_date' => $event['event_date']]
        );
        $stats = [
            'checked_in' => $checkedInCount['checked_in'] ?? 0,
            'rsvp_yes' => 0,
            'total_heads' => 0,
            'not_checked_in_heads' => 0,
        ];
    } catch (\Exception $e2) {
        error_log('Error getting check-in stats: ' . $e2->getMessage());
        $stats = [
            'checked_in' => 0,
            'rsvp_yes' => 0,
            'total_heads' => 0,
            'not_checked_in_heads' => 0,
        ];
    }
}

// Get checked-in members (scoped to event's own date)
$checkedIn = $db->query("
    SELECT u.*, a.checked_in_at 
    FROM attendance a 
    JOIN users u ON a.user_id = u.id 
    WHERE a.event_id = :event_id AND a.checked_in_at IS NOT NULL AND DATE(a.checked_in_at) = :event_date
    ORDER BY a.checked_in_at DESC
    ", ['event_id' => $eventId, 'event_date' => $event['event_date']]);

$userId = AuthMiddleware::getUserId();
$userData = $db->queryOne("SELECT first_name, last_name, email FROM users WHERE id = :id", ['id' => $userId]);
if ($userData) {
    $user = [
        'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
        'email' => $userData['email']
    ];
} else {
    $user = [
        'name' => 'Administrator',
        'email' => 'admin@headcount.local'
    ];
}

$pageTitle = 'Check-In';
$adminUrl = $baseUrl . '/admin/?page=events';

// Calculate API base URL
$apiBase = $baseUrl . '/api';

// Get CSRF token
$csrfToken = CsrfMiddleware::getToken();
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    (function(){var K='headcount-admin-theme';var t=null;try{t=localStorage.getItem(K);}catch(e){}
    var d=t==='dark'||(t!=='light'&&typeof matchMedia!=='undefined'&&matchMedia('(prefers-color-scheme:dark)').matches);
    document.documentElement.classList.toggle('dark',!!d);})();
    </script>
    <meta http-equiv="Permissions-Policy" content="camera=self, microphone=self">
    <title><?= htmlspecialchars($pageTitle) ?> - <?= htmlspecialchars($appName) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath) ?>/public/css/tailwind-output.css">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath) ?>/public/css/modern-design.css">
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath) ?>/public/css/modal.css">
    <script src="<?= htmlspecialchars($basePath) ?>/public/js/modal.js"></script>
    <script src="<?= htmlspecialchars($basePath) ?>/public/js/confirm.js"></script>
    <script src="<?= htmlspecialchars($basePath) ?>/public/js/checkin-offline.js"></script>

    <style>
        [x-cloak] { display: none !important; }
        /* Ensure modal centers properly */
        #confirm-dialog-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }
        #confirm-dialog-modal.active {
            display: flex !important;
        }
    </style>
</head>
<body class="h-full bg-gray-50 text-gray-900 dark:bg-gray-900 dark:text-gray-100">
    <div x-data="checkinApp()" x-init="init()" class="min-h-screen p-6 space-y-8">
        
        <!-- HEADER WITH LOGO AND APP NAME -->
        <div class="max-w-5xl mx-auto mb-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <img src="<?= htmlspecialchars($assetsBase) ?>images/logo.svg" alt="Logo" class="h-10 w-10 flex-shrink-0">
                    <span class="text-2xl font-bold text-gray-900 dark:text-white"><?= htmlspecialchars($appName) ?></span>
                </div>
                <a href="<?= htmlspecialchars($adminUrl) ?>" class="inline-flex items-center gap-2 px-4 py-2 text-sm font-bold text-brand-600 hover:text-brand-700 hover:bg-brand-50 rounded-lg transition-colors dark:text-brand-400 dark:hover:bg-gray-800 dark:hover:text-brand-300">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    Back to Admin
                </a>
            </div>
        </div>

        <!-- OFFLINE / PENDING SYNC BANNER -->
        <div x-show="isOffline || pendingSyncCount > 0" x-cloak class="max-w-5xl mx-auto">
            <div x-show="isOffline" class="ta-alert ta-alert-warning">
                <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                <span class="text-sm font-medium flex-1">You're offline — check-ins will sync when back online.</span>
                <span x-show="pendingSyncCount > 0" class="text-sm font-bold ml-auto">(<span x-text="pendingSyncCount"></span> pending)</span>
            </div>
            <div x-show="!isOffline && (syncingInProgress || pendingSyncCount > 0)" class="bg-brand-50 border border-brand-200 rounded-xl px-4 py-3 flex items-center gap-3">
                <span class="text-sm font-medium text-brand-800" x-text="syncingInProgress ? 'Syncing...' : (pendingSyncCount + ' pending — will sync when you return to this tab')"></span>
            </div>
        </div>
        
        <!-- EVENT CONTEXT HEADER -->
        <?php
        $eventStats = ['checked_in' => $stats['checked_in'] ?? 0, 'rsvp_yes' => $stats['rsvp_yes'] ?? 0];
        $eventActions = '';
        $initialHeads = (int) ($stats['total_heads'] ?? 0);
        $initialRegs = (int) ($stats['rsvp_yes'] ?? 0);
        $regWord = $initialRegs === 1 ? 'registrant' : 'registrants';
        $eventHeaderStatsHtml = '
            <div class="rounded-2xl border border-gray-200 bg-white p-5 min-w-[148px] shadow-sm dark:border-gray-600 dark:bg-gray-900/80">
                <div class="text-3xl font-black leading-none text-gray-900 dark:text-white"><span x-text="totalRsvps">' . $initialHeads . '</span></div>
                <div class="mt-1.5 text-[10px] font-bold uppercase tracking-wider text-gray-500 dark:text-gray-400">People</div>
                <div class="mt-1 text-[11px] font-medium text-gray-400 dark:text-gray-500">
                    <span x-text="registrantYesCount">' . $initialRegs . '</span>
                    <span> </span>
                    <span x-text="registrantYesCount === 1 ? \'registrant\' : \'registrants\'">' . $regWord . '</span>
                </div>
            </div>
            <div class="bg-emerald-50/80 rounded-2xl p-4 border border-emerald-100/50 min-w-[110px] text-center dark:bg-emerald-950/40 dark:border-emerald-800/50">
                <div class="text-2xl font-black text-emerald-600 mb-0.5 dark:text-emerald-400" x-text="checkedInCount"></div>
                <div class="text-[10px] font-bold text-emerald-600 uppercase tracking-wider dark:text-emerald-300/90">Checked in</div>
            </div>
            <div class="bg-amber-50/80 rounded-2xl p-4 border border-amber-100/50 min-w-[110px] text-center dark:bg-amber-950/35 dark:border-amber-800/50">
                <div class="text-2xl font-black text-amber-600 mb-0.5 dark:text-amber-300" x-text="pendingCount"></div>
                <div class="text-[10px] font-bold text-amber-600 uppercase tracking-wider dark:text-amber-200/90">Not checked in</div>
            </div>';
        require __DIR__ . '/components/event-header.php';
        ?>

        <!-- SEARCH INTERFACE (COMMAND PALETTE STYLE) -->
        <div class="max-w-5xl mx-auto">
        <div class="relative group">
            <div class="absolute -inset-1 bg-gradient-to-r from-brand-500 to-purple-600 rounded-[2.5rem] blur opacity-25 group-focus-within:opacity-40 transition duration-1000 group-focus-within:duration-200 mt-1"></div>
            <div class="relative overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:bg-gray-800 dark:border-gray-700">
                <div class="flex items-center px-8 py-6 gap-4">
                    <svg class="w-8 h-8 text-brand-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    <input 
                        type="text" 
                        x-model="searchQuery"
                        @input.debounce.200ms="search()"
                        @keydown.escape="searchQuery = ''; results = []"
                        placeholder="Search by name, email, or phone..."
                        class="flex-1 bg-transparent text-2xl font-bold text-gray-900 placeholder-gray-300 outline-none dark:text-white"
                        autofocus
                    >
                    <div class="flex items-center gap-2">
                        <button 
                            @click="toggleQRScanner()"
                            class="btn-primary flex items-center gap-2"
                            title="Scan QR Code"
                            type="button"
                        >
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"></path></svg>
                            <span class="hidden md:inline">QR Scan</span>
                        </button>
                        <span class="hidden md:block text-[10px] font-bold text-gray-400 bg-gray-100 px-2 py-1 rounded-md dark:bg-gray-800">ESC TO CLEAR</span>
                    </div>
                </div>

                <!-- RESULTS PANEL -->
                <div x-show="searchQuery.length > 0" class="border-t border-gray-50 max-h-[500px] overflow-y-auto bg-gray-50/30 dark:bg-gray-800">
                    <div x-show="searching" class="p-12 text-center">
                        <div class="inline-block animate-spin w-8 h-8 border-4 border-brand-500 border-t-transparent rounded-full"></div>
                        <p class="mt-4 text-gray-500 font-bold uppercase tracking-widest text-xs dark:text-gray-400">Searching Database...</p>
                    </div>
                    <div x-show="offlineSearchHint && !searching" class="px-4 py-2 text-center">
                        <p class="text-xs font-medium text-amber-600">Offline — searching cached list</p>
                    </div>

                    <div x-show="!searching && results.length === 0 && searchQuery.length >= 2" class="p-12 text-center">
                        <div class="bg-white rounded-2xl p-8 border border-gray-100 inline-block dark:bg-gray-800 dark:border-gray-800">
                            <p class="text-gray-400 mb-6 font-medium">No members found matching "<span class="text-gray-900 font-bold dark:text-white" x-text="searchQuery"></span>"</p>
                            <button @click="showAddMember = true" class="btn-primary">
                                <span class="flex items-center gap-2">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
                                    Add New Member
                                </span>
                            </button>
                        </div>
                    </div>

                    <div class="p-4 grid grid-cols-1 md:grid-cols-2 gap-4">
                        <template x-for="member in results" :key="member.id">
                            <div 
                                :data-member-id="member.id"
                                class="p-5 rounded-2xl border transition-all duration-300 cursor-pointer"
                                :class="member.checked_in 
                                    ? 'bg-emerald-50 border-emerald-200' 
                                    : 'bg-white border-gray-200 hover:border-brand-300 hover:shadow-card'"
                                @click="!member.checked_in && checkIn(member.id)"
                            >
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div class="w-12 h-12 rounded-xl flex items-center justify-center font-black text-xl"
                                             :class="member.checked_in ? 'bg-emerald-200 text-emerald-700' : 'bg-brand-100 text-brand-600'">
                                            <span x-text="member.first_name[0] + member.last_name[0]"></span>
                                        </div>
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <h3 class="font-bold text-gray-900 leading-tight dark:text-white" x-text="member.first_name + ' ' + member.last_name"></h3>
                                                <span class="px-1.5 py-0.5 rounded text-[8px] font-bold uppercase tracking-wider"
                                                      :class="member.user_type === 'Member' ? 'bg-brand-50 text-brand-600' : 'bg-orange-50 text-orange-600'"
                                                      x-text="member.user_type"></span>
                                            </div>
                                            <p class="text-xs text-gray-500 font-medium mt-0.5 dark:text-gray-400" x-text="member.email || member.phone || 'No contact info'"></p>
                                            <template x-if="member.rsvp_status === 'yes'">
                                                <div class="mt-1 flex items-center gap-1.5">
                                                    <span class="text-[9px] font-bold text-emerald-600 uppercase">RSVP YES</span>
                                                    <template x-if="member.guest_count > 0">
                                                        <span class="text-[9px] font-bold text-brand-500 uppercase" x-text="'+ ' + member.guest_count + ' GUESTS'"></span>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                    
                                    <template x-if="member.checked_in">
                                        <div class="flex flex-col items-end">
                                            <span class="text-[10px] font-black text-emerald-600 uppercase">✓ ARRIVED</span>
                                            <button @click.stop="undoCheckIn(member.id)" class="text-[10px] text-gray-400 hover:text-rose-500 mt-1 font-bold underline">UNDO</button>
                                        </div>
                                    </template>
                                    
                                    <template x-if="!member.checked_in">
                                        <div class="w-8 h-8 rounded-full border-2 border-brand-100 flex items-center justify-center text-brand-400 group-hover:bg-brand-500 group-hover:text-white transition-all">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                        </div>
                                    </template>
                                </div>
                                <!-- Cash Payment (per attendee) - only for paid events -->
                                <div x-show="isPaidEvent" class="mt-3 pt-3 border-t border-gray-100 flex items-center gap-2 flex-wrap dark:border-gray-800" @click.stop>
                                    <template x-if="member.payment_id && member.payment_method === 'cash'">
                                        <div class="flex items-center gap-2">
                                            <template x-if="!member.cashEditing">
                                                <span class="text-xs text-gray-600 dark:text-gray-300">Cash paid $<span x-text="member.payment_amount ? parseFloat(member.payment_amount).toFixed(2) : '0.00'"></span></span>
                                            </template>
                                            <input x-show="member.cashEditing" type="number" step="0.01" min="0" x-model="member.cashEditAmount" placeholder="Amount" class="w-20 text-xs border border-gray-200 rounded px-2 py-1 dark:border-gray-700">
                                            <button type="button" @click="toggleCashEdit(member)" class="text-[10px] font-bold text-brand-600 hover:underline" x-text="member.cashEditing ? 'Save' : 'Edit'"></button>
                                            <button type="button" x-show="member.cashEditing" @click="member.cashEditing = false" class="text-[10px] text-gray-500 hover:underline dark:text-gray-400">Cancel</button>
                                            <button type="button" @click="deleteCashPayment(member)" class="text-[10px] font-bold text-rose-600 hover:underline">Delete</button>
                                        </div>
                                    </template>
                                    <template x-if="member.payment_id && member.payment_method === 'stripe'">
                                        <span class="text-xs text-gray-500 dark:text-gray-400">Paid $<span x-text="parseFloat(member.payment_amount).toFixed(2)"></span> (card)<span x-show="member.is_refunded" class="ml-1 text-rose-600 font-bold">Refunded</span></span>
                                    </template>
                                    <template x-if="member.payment_id && member.is_refunded && member.payment_method !== 'stripe'">
                                        <span class="text-xs text-rose-600 font-bold">Refunded</span>
                                    </template>
                                    <template x-if="!member.payment_id">
                                        <div class="flex items-center gap-2">
                                            <span class="text-[10px] font-bold text-gray-500 uppercase dark:text-gray-400">Cash</span>
                                            <input type="number" step="0.01" min="0.01" x-model="member.cashAmount" placeholder="0.00" class="w-20 text-xs border border-gray-200 rounded px-2 py-1 dark:border-gray-700">
                                            <button type="button" @click="recordCashPayment(member)" class="text-[10px] font-bold text-emerald-600 hover:underline">Record</button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- EMPTY STATE (HINT) -->
                <div x-show="searchQuery.length === 0" class="p-8 text-center bg-gray-50/50 dark:bg-gray-800">
                    <p class="text-sm font-bold text-gray-400 uppercase tracking-widest">Type to search members by name or email</p>
                </div>
            </div>
        </div>
        </div>

        <!-- ALL RSVPs LIST (TICKET-002) -->
        <div class="max-w-5xl mx-auto mt-8">
            <div x-show="canCorrectCheckins" class="ta-alert ta-alert-warning mb-3 flex-col items-start text-sm">
                <p class="font-semibold">Outside live check-in hours?</p>
                <p class="mt-1 opacity-90">Use <strong>Record attendance</strong> in the table (logged correction with a reason). <strong>Check In</strong> only works during the event window.</p>
            </div>
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-sm font-black text-gray-500 uppercase tracking-wider dark:text-gray-400">All RSVPs</h2>
                <input type="text" x-model="rsvpListFilter" placeholder="Filter by name or email..." class="text-sm border border-gray-200 rounded-lg px-3 py-2 w-64 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none dark:border-gray-700">
            </div>
            <div class="overflow-hidden bento-card">
                <div x-show="loadingRsvpList" class="p-8 text-center">
                    <div class="inline-block animate-spin w-8 h-8 border-4 border-brand-500 border-t-transparent rounded-full"></div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Loading RSVP list...</p>
                </div>
                <div x-show="!loadingRsvpList && filteredRsvpList.length === 0" class="p-8 text-center text-gray-500 text-sm dark:text-gray-400">No RSVPs to show.</div>
                <div x-show="!loadingRsvpList && filteredRsvpList.length > 0" class="max-h-[400px] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 border-b border-gray-200 sticky top-0 dark:bg-gray-800 dark:border-gray-700">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase dark:text-gray-400">Name</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase dark:text-gray-400">Contact</th>
                                <th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase dark:text-gray-400">Status</th>
                                <template x-if="isPaidEvent"><th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase dark:text-gray-400">Cash / Payment</th></template>
                                <template x-if="hasEventQuestions"><th class="px-4 py-3 text-left text-xs font-bold text-gray-500 uppercase dark:text-gray-400">Questions</th></template>
                                <th class="px-4 py-3 text-right text-xs font-bold text-gray-500 uppercase dark:text-gray-400">Action</th>
                            </tr>
                        </thead>
                        <template x-for="member in filteredRsvpList" :key="member.id">
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <tr class="hover:bg-gray-50/50 dark:bg-gray-800">
                                        <td class="px-4 py-3 font-medium text-gray-900 dark:text-white" x-text="member.first_name + ' ' + member.last_name"></td>
                                        <td class="px-4 py-3 text-gray-500 dark:text-gray-400" x-text="member.email || member.phone || '\u2014'"></td>
                                        <td class="px-4 py-3">
                                            <span x-show="member.checked_in" class="text-xs font-bold text-emerald-600 uppercase">Checked In</span>
                                            <span x-show="!member.checked_in" class="text-xs font-bold text-amber-600 uppercase">Not Yet Checked In</span>
                                        </td>
                                        <template x-if="isPaidEvent">
                                            <td class="px-4 py-3">
                                                <template x-if="member.payment_id && member.payment_method === 'cash'">
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-xs text-gray-600 dark:text-gray-300">$<span x-text="member.payment_amount ? parseFloat(member.payment_amount).toFixed(2) : '0.00'"></span></span>
                                                        <input x-show="member.cashEditing" type="number" step="0.01" min="0" x-model="member.cashEditAmount" class="w-16 text-xs border rounded px-1 py-0.5">
                                                        <button type="button" @click="toggleCashEdit(member)" class="text-[10px] font-bold text-brand-600 hover:underline" x-text="member.cashEditing ? 'Save' : 'Edit'"></button>
                                                        <button type="button" @click="deleteCashPayment(member)" class="text-[10px] font-bold text-rose-600 hover:underline">Delete</button>
                                                    </div>
                                                </template>
                                                <template x-if="member.payment_id && member.payment_method === 'stripe'">
                                                    <span class="text-xs text-gray-500 dark:text-gray-400">$<span x-text="parseFloat(member.payment_amount).toFixed(2)"></span> (card)<span x-show="member.is_refunded" class="ml-1 text-rose-600 font-bold">Refunded</span></span>
                                                </template>
                                                <template x-if="member.payment_id && member.is_refunded && (member.payment_method || '').toLowerCase() !== 'stripe'">
                                                    <span class="text-xs text-rose-600 font-bold">Refunded</span>
                                                </template>
                                                <template x-if="!member.payment_id">
                                                    <div class="flex items-center gap-2">
                                                        <input type="number" step="0.01" min="0.01" x-model="member.cashAmount" placeholder="0" class="w-16 text-xs border border-gray-200 rounded px-1 py-0.5 dark:border-gray-700">
                                                        <button type="button" @click="recordCashPayment(member)" class="text-[10px] font-bold text-emerald-600 hover:underline">Record</button>
                                                    </div>
                                                </template>
                                            </td>
                                        </template>
                                        <template x-if="hasEventQuestions">
                                            <td class="px-4 py-3">
                                                <template x-if="(member.question_answers || []).length > 0">
                                                    <button type="button" @click="expandedRsvpId = expandedRsvpId === member.rsvp_id ? null : member.rsvp_id"
                                                            class="text-xs font-medium text-brand-600 hover:underline"
                                                            x-text="expandedRsvpId === member.rsvp_id ? 'Hide' : 'View answers'"></button>
                                                </template>
                                                <template x-if="!(member.question_answers || []).length">
                                                    <span class="text-gray-400 text-xs" x-text="'\u2014'"></span>
                                                </template>
                                            </td>
                                        </template>
                                        <td class="px-4 py-3 text-right space-y-1">
                                            <template x-if="member.checked_in">
                                                <div class="flex flex-col items-end gap-1">
                                                    <button type="button" @click="undoCheckIn(member.id)" class="text-xs font-medium text-rose-600 hover:underline">Undo</button>
                                                    <button type="button" x-show="canCorrectCheckins"
                                                            @click="openCorrectionModalForMember(member, 'undo')"
                                                            class="text-[10px] font-bold text-rose-700 hover:underline">Remove (logged)</button>
                                                </div>
                                            </template>
                                            <template x-if="!member.checked_in">
                                                <div class="flex flex-col items-end gap-1">
                                                    <button type="button" @click="checkIn(member.id)" class="text-xs font-medium text-brand-600 hover:underline">Check In</button>
                                                    <button type="button" x-show="canCorrectCheckins"
                                                            @click="openCorrectionModalForMember(member, 'checkin')"
                                                            class="text-[10px] font-bold text-amber-800 hover:underline">Record attendance</button>
                                                </div>
                                            </template>
                                        </td>
                                    </tr>
                                    <tr x-show="hasEventQuestions && expandedRsvpId === member.rsvp_id" x-cloak class="bg-gray-50/80 dark:bg-gray-800">
                                        <td :colspan="rsvpTableColspan" class="px-4 py-3">
                                            <div class="text-sm space-y-1.5">
                                                <template x-for="qa in (member.question_answers || [])" :key="qa.question_text">
                                                    <div class="flex gap-2">
                                                        <span class="font-medium text-gray-600 min-w-[120px] dark:text-gray-300" x-text="qa.question_text + ':'"></span>
                                                        <span class="text-gray-800 dark:text-gray-100" x-text="qa.answer_text || '\u2014'"></span>
                                                    </div>
                                                </template>
                                            </div>
                                        </td>
                                    </tr>
                            </tbody>
                            </template>
                    </table>
                </div>
            </div>
        </div>

        <!-- RECENTLY CHECKED IN LIST -->
        <div class="max-w-5xl mx-auto space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-xs font-black text-gray-400 uppercase tracking-[0.2em] px-2 flex items-center gap-3">
                    <span>Recent Arrivals</span>
                    <div class="flex-1 h-px bg-gray-100 dark:bg-gray-800"></div>
                    <span class="text-brand-500" x-text="checkedInCount"></span>
                </h2>
                <!-- View Toggle -->
                <div class="flex items-center gap-1 bg-gray-100 rounded-xl p-1 dark:bg-gray-800">
                    <button 
                        @click="checkinViewMode = 'list'; saveCheckinViewPreference('list')"
                        :class="checkinViewMode === 'list' ? 'bg-white text-brand-600 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                        class="px-3 py-1.5 rounded-lg transition-all font-bold text-sm"
                        title="List View"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                        </svg>
                    </button>
                    <button 
                        @click="checkinViewMode = 'card'; saveCheckinViewPreference('card')"
                        :class="checkinViewMode === 'card' ? 'bg-white text-brand-600 shadow-sm' : 'text-gray-500 hover:text-gray-700'"
                        class="px-3 py-1.5 rounded-lg transition-all font-bold text-sm"
                        title="Card View"
                    >
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"></path>
                        </svg>
                    </button>
                </div>
            </div>
            
            <!-- List View -->
            <div x-show="checkinViewMode === 'list'" class="overflow-hidden bento-card">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50 border-b border-gray-200 dark:bg-gray-800 dark:border-gray-700">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Member</th>
                                <th class="px-6 py-3 text-left text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Check-In Time</th>
                                <th class="px-6 py-3 text-right text-xs font-bold text-gray-500 uppercase tracking-wider dark:text-gray-400">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200 dark:bg-gray-800 dark:divide-gray-700">
                            <template x-for="member in paginatedMembers" :key="member.id">
                                <tr class="hover:bg-gray-50 transition-colors dark:bg-gray-800">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-lg bg-brand-100 flex items-center justify-center text-sm font-bold text-brand-600">
                                                <span x-text="member.first_name[0] + member.last_name[0]"></span>
                                            </div>
                                            <div>
                                                <div class="text-sm font-bold text-gray-900 dark:text-white" x-text="member.first_name + ' ' + member.last_name"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="text-sm text-gray-600 dark:text-gray-300" x-text="member.checked_in_time"></div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <button @click="undoCheckIn(member.id)" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-rose-600 hover:text-rose-700 hover:bg-rose-50 rounded-lg transition-colors">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                            Undo
                                        </button>
                                    </td>
                                </tr>
                            </template>
                            <template x-if="checkedInMembers.length === 0">
                                <tr>
                                    <td colspan="3" class="px-6 py-12 text-center">
                                        <div class="text-gray-300 font-bold uppercase tracking-widest text-xs">
                                            Waiting for first arrival...
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                
                <!-- Pagination -->
                <div x-show="checkedInMembers.length > 0" class="px-6 py-4 border-t border-gray-200 bg-gray-50 flex items-center justify-between dark:bg-gray-800 dark:border-gray-700">
                    <div class="text-sm text-gray-600 dark:text-gray-300">
                        Showing <span class="font-semibold" x-text="(currentPage - 1) * itemsPerPage + 1"></span> to 
                        <span class="font-semibold" x-text="Math.min(currentPage * itemsPerPage, checkedInMembers.length)"></span> of 
                        <span class="font-semibold" x-text="checkedInMembers.length"></span> arrivals
                    </div>
                    <div class="flex items-center gap-2">
                        <button 
                            @click="currentPage = Math.max(1, currentPage - 1)"
                            :disabled="currentPage === 1"
                            :class="currentPage === 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-200'"
                            class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg transition-colors dark:bg-gray-800 dark:text-gray-200"
                        >
                            Previous
                        </button>
                        <span class="text-sm text-gray-600 dark:text-gray-300">
                            Page <span class="font-semibold" x-text="currentPage"></span> of <span class="font-semibold" x-text="totalPages"></span>
                        </span>
                        <button 
                            @click="currentPage = Math.min(totalPages, currentPage + 1)"
                            :disabled="currentPage === totalPages"
                            :class="currentPage === totalPages ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-200'"
                            class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg transition-colors dark:bg-gray-800 dark:text-gray-200"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Card View -->
            <div x-show="checkinViewMode === 'card'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <template x-for="member in paginatedMembers" :key="member.id">
                    <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-card transition-all hover:border-brand-200 dark:bg-gray-800 dark:border-gray-700">
                        <div class="flex items-center gap-4 mb-4">
                            <div class="w-12 h-12 rounded-xl bg-brand-100 flex items-center justify-center text-sm font-bold text-brand-600 flex-shrink-0">
                                <span x-text="member.first_name[0] + member.last_name[0]"></span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-sm font-bold text-gray-900 truncate dark:text-white" x-text="member.first_name + ' ' + member.last_name"></h3>
                                <p class="text-xs text-gray-500 mt-0.5 dark:text-gray-400" x-text="member.checked_in_time"></p>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <button @click="undoCheckIn(member.id)" class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-rose-600 hover:text-rose-700 hover:bg-rose-50 rounded-lg transition-colors">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                Undo
                            </button>
                        </div>
                    </div>
                </template>
                <template x-if="checkedInMembers.length === 0">
                    <div class="col-span-full bg-white rounded-2xl border border-gray-100 p-12 text-center dark:bg-gray-800 dark:border-gray-800">
                        <div class="text-gray-300 font-bold uppercase tracking-widest text-xs">
                            Waiting for first arrival...
                        </div>
                    </div>
                </template>
                
                <!-- Pagination for Card View -->
                <div x-show="checkedInMembers.length > 0" class="col-span-full px-6 py-4 border-t border-gray-200 bg-gray-50 flex items-center justify-between dark:bg-gray-800 dark:border-gray-700">
                    <div class="text-sm text-gray-600 dark:text-gray-300">
                        Showing <span class="font-semibold" x-text="(currentPage - 1) * itemsPerPage + 1"></span> to 
                        <span class="font-semibold" x-text="Math.min(currentPage * itemsPerPage, checkedInMembers.length)"></span> of 
                        <span class="font-semibold" x-text="checkedInMembers.length"></span> arrivals
                    </div>
                    <div class="flex items-center gap-2">
                        <button 
                            @click="currentPage = Math.max(1, currentPage - 1)"
                            :disabled="currentPage === 1"
                            :class="currentPage === 1 ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-200'"
                            class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg transition-colors dark:bg-gray-800 dark:text-gray-200"
                        >
                            Previous
                        </button>
                        <span class="text-sm text-gray-600 dark:text-gray-300">
                            Page <span class="font-semibold" x-text="currentPage"></span> of <span class="font-semibold" x-text="totalPages"></span>
                        </span>
                        <button 
                            @click="currentPage = Math.min(totalPages, currentPage + 1)"
                            :disabled="currentPage === totalPages"
                            :class="currentPage === totalPages ? 'opacity-50 cursor-not-allowed' : 'hover:bg-gray-200'"
                            class="px-3 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg transition-colors dark:bg-gray-800 dark:text-gray-200"
                        >
                            Next
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- TOAST SYSTEM -->
        <div 
            x-show="showSuccess" 
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 transform translate-y-10"
            x-transition:enter-end="opacity-100 transform translate-y-0"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100 transform translate-y-0"
            x-transition:leave-end="opacity-0 transform translate-y-10"
            class="fixed bottom-8 left-1/2 -translate-x-1/2 z-50"
        >
            <div class="flex items-center gap-3 rounded-full bg-gray-900/95 px-8 py-4 text-white shadow-[0_12px_40px_rgba(0,0,0,0.35)] backdrop-blur-md">
                <div class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></div>
                <span class="font-bold tracking-tight" x-text="successMessage"></span>
            </div>
        </div>
        
        <!-- ADD MEMBER MODAL (backdrop absolute inside fixed so panel stays above dimmer) -->
        <div 
            x-show="showAddMember" 
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="fixed inset-0 z-[10000] flex items-center justify-center p-4"
            style="display: none;"
            @keydown.escape.window="showAddMember = false"
        >
            <div class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="showAddMember = false" aria-hidden="true"></div>
            <div class="relative z-10 max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl border border-gray-200 bg-white shadow-card-lg dark:bg-gray-800 dark:border-gray-700" @click.stop>
                <div class="sticky top-0 bg-white border-b border-gray-200 px-6 py-4 flex items-center justify-between rounded-t-2xl dark:bg-gray-800 dark:border-gray-700">
                    <h2 class="text-xl font-bold text-gray-900 dark:text-white">Add New Member</h2>
                    <button @click="showAddMember = false" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <form @submit.prevent="addNewMember()" class="p-6 space-y-4">
                    <div x-show="addMemberError" class="ta-alert ta-alert-error text-sm" x-text="addMemberError"></div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-200">First Name *</label>
                        <input 
                            type="text" 
                            x-model="newMember.first_name"
                            required
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                            placeholder="John"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-200">Last Name *</label>
                        <input 
                            type="text" 
                            x-model="newMember.last_name"
                            required
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                            placeholder="Doe"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-200">Email *</label>
                        <input 
                            type="email" 
                            x-model="newMember.email"
                            required
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                            placeholder="john.doe@example.com"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-200">Phone</label>
                        <input 
                            type="tel" 
                            x-model="newMember.phone"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                            placeholder="(555) 123-4567"
                        >
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2 dark:text-gray-200">Gender</label>
                        <select 
                            x-model="newMember.gender"
                            class="w-full border border-gray-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:border-transparent"
                        >
                            <option value="">Select gender...</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    
                    <div class="flex gap-3 pt-4">
                        <button 
                            type="submit" 
                            :disabled="addingMember"
                            class="flex-1 btn-primary py-2.5 px-4 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span x-show="!addingMember">Add Member</span>
                            <span x-show="addingMember" class="flex items-center justify-center gap-2">
                                <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                Adding...
                            </span>
                        </button>
                        <button 
                            type="button" 
                            @click="showAddMember = false"
                            class="px-4 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition-colors dark:bg-gray-800 dark:text-gray-200"
                        >
                            Cancel
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- QR Code Scanner Modal -->
        <div x-show="showQRScanner" 
             x-cloak
             @keydown.escape.window="toggleQRScanner()"
             class="fixed inset-0 z-[10000] flex items-center justify-center p-4 overflow-y-auto"
             style="display: none;">
            
            <div class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="toggleQRScanner()" aria-hidden="true"></div>
            
            <div class="relative z-10 w-full max-w-2xl bg-white rounded-2xl shadow-xl dark:bg-gray-800" @click.stop>
                    <div class="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
                        <h3 class="text-2xl font-bold text-gray-900 dark:text-white">QR Code Scanner</h3>
                        <button @click="toggleQRScanner()" class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-300">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                    
                    <div class="p-6">
                        <div id="qr-scanner-container" class="mx-auto mb-4 bg-gray-100 rounded-lg overflow-hidden dark:bg-gray-800" style="position: relative; width: 100%; max-width: 500px; height: 500px; display: block;">
                            <div id="qr-scanner-loading" class="flex items-center justify-center absolute inset-0 bg-gray-100 z-10 dark:bg-gray-800">
                                <div class="text-center text-gray-500 dark:text-gray-400">
                                    <div class="animate-spin w-8 h-8 border-4 border-brand-500 border-t-transparent rounded-full mx-auto mb-2"></div>
                                    <p class="font-medium">Initializing camera...</p>
                                </div>
                            </div>
                        </div>
                        <div id="qr-scan-status" class="text-center text-gray-600 mb-4 font-medium dark:text-gray-300">Position QR code within the frame</div>
                        <div id="qr-scan-result" class="p-4 bg-gray-50 rounded-lg hidden dark:bg-gray-800">
                            <div id="qr-result-content"></div>
                        </div>
                    </div>
                </div>
        </div>

        <!-- Guest count modal (when checking in someone with guests) -->
        <div x-show="showGuestCountModal" x-cloak
             class="fixed inset-0 z-[10000] flex items-center justify-center p-4"
             style="display: none;">
            <div class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="showGuestCountModal = false" aria-hidden="true"></div>
            <div class="relative z-10 w-full max-w-sm bg-white rounded-2xl shadow-xl p-6 dark:bg-gray-800" @click.stop>
                <h3 class="text-lg font-bold text-gray-900 mb-2 dark:text-white">How many people did they come with?</h3>
                <p class="text-sm text-gray-600 mb-4 dark:text-gray-300" x-text="guestCountModalMemberName ? (guestCountModalMemberName + ' has guests on their RSVP.') : ''"></p>
                <div class="mb-6">
                    <label for="guest-count-input" class="block text-sm font-medium text-gray-700 mb-1 dark:text-gray-200">Number of guests with them</label>
                    <input type="number" id="guest-count-input" min="0" max="20" step="1"
                           x-model.number="guestCountModalValue"
                           class="ta-input w-full">
                </div>
                <div class="flex gap-3 justify-end">
                    <button type="button" @click="showGuestCountModal = false; guestCountModalUserId = null; guestCountModalMemberName = ''; guestCountModalValue = 0"
                            class="px-4 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 transition-colors dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <button type="button" @click="confirmGuestCountAndCheckIn()"
                            class="btn-primary py-2.5 px-4">Confirm check-in</button>
                </div>
            </div>
        </div>

        <!-- Attendance correction modal -->
        <div x-show="showCorrectionModal" x-cloak
             class="fixed inset-0 z-[10001] flex items-center justify-center p-4"
             style="display: none;" role="dialog" aria-modal="true">
            <div class="absolute inset-0 bg-gray-900/55 backdrop-blur-[1px]" @click="closeCorrectionModal()" aria-hidden="true"></div>
            <div class="relative z-10 w-full max-w-md bg-white rounded-2xl shadow-xl p-6 dark:bg-gray-800" @click.stop>
                <h3 class="text-lg font-bold text-gray-900 mb-1 dark:text-white" x-text="correctionForm.action === 'undo' ? 'Remove check-in' : 'Record attendance'"></h3>
                <p class="text-sm text-gray-600 mb-4 dark:text-gray-300" x-text="correctionForm.user_name"></p>
                <div class="space-y-4">
                    <div x-show="correctionForm.action !== 'undo'">
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1 dark:text-gray-400">Check-in time</label>
                        <input type="datetime-local" x-model="correctionForm.checked_in_at_local" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm dark:border-gray-700">
                    </div>
                    <div x-show="correctionForm.action === 'checkin' || correctionForm.action === 'update'">
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1 dark:text-gray-400">Guests checked in</label>
                        <input type="number" min="0" max="20" x-model.number="correctionForm.guests_checked_in" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm dark:border-gray-700">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-500 uppercase mb-1 dark:text-gray-400">Reason (required)</label>
                        <textarea x-model="correctionForm.reason" rows="3" class="w-full border border-gray-200 rounded-lg px-3 py-2 text-sm dark:border-gray-700" placeholder="e.g. Verified in person after event"></textarea>
                    </div>
                </div>
                <div class="flex gap-3 justify-end mt-6">
                    <button type="button" @click="closeCorrectionModal()" class="px-4 py-2.5 border border-gray-300 text-gray-700 font-medium rounded-lg hover:bg-gray-50 dark:bg-gray-800 dark:text-gray-200">Cancel</button>
                    <button type="button" @click="submitCorrection()" :disabled="correctionSaving" class="btn-primary py-2.5 px-4 disabled:opacity-50" x-text="correctionSaving ? 'Saving...' : 'Confirm'"></button>
                </div>
            </div>
        </div>
        
    </div>

    <script>
        // CSRF token for API requests
        const csrfToken = '<?= htmlspecialchars($csrfToken) ?>';
        
        function checkinApp() {
            const Offline = window.HeadcountCheckinOffline || {};
            return {
                eventId: <?= $eventId ?>,
                organizationId: <?= (int)$organizationId ?>,
                isPaidEvent: <?= $isPaidEvent ? 'true' : 'false' ?>,
                hasEventQuestions: <?= $hasEventQuestions ? 'true' : 'false' ?>,
                canCorrectCheckins: <?= $canCorrectCheckins ? 'true' : 'false' ?>,
                eventDate: <?= json_encode($eventDateYmdCheckin) ?>,
                eventStartTime: <?= json_encode($eventStartTimeCheckin) ?>,
                apiBase: '<?= htmlspecialchars($apiBase) ?>',
                showCorrectionModal: false,
                correctionSaving: false,
                correctionForm: {
                    action: 'checkin',
                    user_id: null,
                    user_name: '',
                    reason: '',
                    checked_in_at_local: '',
                    guests_checked_in: 0,
                },
                searchQuery: '',
                results: [],
                searching: false,
                isOffline: false,
                pendingSyncCount: 0,
                syncingInProgress: false,
                offlineSearchHint: false,
                checkedInMembers: <?= json_encode(array_map(function($m) use ($orgTimezone) {
                    return [
                        'id' => $m['id'],
                        'first_name' => $m['first_name'],
                        'last_name' => $m['last_name'],
                        'checked_in_time' => formatAttendanceLocalTimeForOrganization($m['checked_in_at'] ?? null, $orgTimezone)
                    ];
                }, $checkedIn)) ?>,
                checkedInCount: <?= count($checkedIn) ?>,
                showSuccess: false,
                successMessage: '',
                showAddMember: false,
                addingMember: false,
                addMemberError: '',
                showQRScanner: false,
                checkinViewMode: 'list', // 'list' or 'card'
                expandedRsvpId: null,
                // Guest count modal (when checking in someone who has guests)
                showGuestCountModal: false,
                guestCountModalUserId: null,
                guestCountModalMemberName: '',
                guestCountModalValue: 0,
                newMember: {
                    first_name: '',
                    last_name: '',
                    email: '',
                    phone: '',
                    gender: ''
                },
                // Pagination
                currentPage: 1,
                itemsPerPage: 10,
                // RSVP list (TICKET-002)
                totalRsvps: <?= (int)($stats['total_heads'] ?? 0) ?>,
                registrantYesCount: <?= (int)($stats['rsvp_yes'] ?? 0) ?>,
                pendingCount: <?= (int)($stats['not_checked_in_heads'] ?? 0) ?>,
                rsvpList: [],
                rsvpListFilter: '',
                loadingRsvpList: false,
                
                async init() {
                    const savedView = localStorage.getItem('checkinViewMode');
                    if (savedView === 'list' || savedView === 'card') {
                        this.checkinViewMode = savedView;
                    }
                    this.isOffline = (Offline.isOnline ? !Offline.isOnline() : false);
                    const updatePending = () => {
                        if (Offline.getQueueCount) Offline.getQueueCount(this.eventId).then(n => { this.pendingSyncCount = n; });
                    };
                    updatePending();
                    if (this.isOffline && Offline.getEventCache) {
                        const cached = await Offline.getEventCache(this.organizationId, this.eventId);
                        if (cached && cached.rsvps && cached.rsvps.length) {
                            this.rsvpList = cached.rsvps;
                            this.totalRsvps = this.sumRsvpYesHeads(cached.rsvps);
                            this.registrantYesCount = (cached.rsvps || []).filter(m => String(m.rsvp_status || '').toLowerCase() === 'yes').length;
                            this.checkedInCount = (cached.checkedInIds || []).length;
                            this.pendingCount = this.sumRsvpYesHeadsPending(cached.rsvps);
                            const checkedIn = (cached.rsvps || []).filter(m => m.checked_in);
                            this.checkedInMembers = checkedIn.map(m => ({
                                id: m.id,
                                first_name: m.first_name,
                                last_name: m.last_name,
                                checked_in_time: m.checked_in_time || this.formatTime(m.checked_in_at)
                            }));
                        }
                    } else {
                        await this.loadRsvpList();
                    }
                    if (Offline.onOnline) Offline.onOnline(() => {
                        this.isOffline = false;
                        this.flushAndRefresh();
                        updatePending();
                    });
                    if (Offline.onOffline) Offline.onOffline(() => {
                        this.isOffline = true;
                        updatePending();
                    });
                    if (Offline.isOnline && Offline.isOnline()) {
                        this.refreshInterval = setInterval(() => {
                            if (document.visibilityState === 'visible' && Offline.isOnline && Offline.isOnline()) this.loadRsvpList();
                        }, 45000);
                    }
                    window.addEventListener('focus', () => {
                        if (Offline.isOnline && Offline.isOnline() && document.visibilityState === 'visible') this.flushAndRefresh();
                    });
                },
                async flushAndRefresh() {
                    if (!window.HeadcountCheckinOffline || !window.HeadcountCheckinOffline.flushQueueForEvent) return;
                    this.syncingInProgress = true;
                    const res = await window.HeadcountCheckinOffline.flushQueueForEvent(this.apiBase, this.organizationId, this.eventId, 'same-origin');
                    if (res.success && Array.isArray(res.rsvps) && res.rsvps.length > 0) {
                        this.rsvpList = res.rsvps;
                        this.totalRsvps = res.total_heads != null ? res.total_heads : this.sumRsvpYesHeads(res.rsvps);
                        if (res.total_registrants_yes != null) {
                            this.registrantYesCount = res.total_registrants_yes;
                        } else {
                            this.registrantYesCount = (res.rsvps || []).filter(m => String(m.rsvp_status || '').toLowerCase() === 'yes').length;
                        }
                        this.checkedInCount = res.checked_in != null ? res.checked_in : res.rsvps.filter(m => m.checked_in).length;
                        this.pendingCount = res.not_checked_in_heads != null ? res.not_checked_in_heads : this.sumRsvpYesHeadsPending(res.rsvps);
                        this.checkedInMembers = res.rsvps.filter(m => m.checked_in).map(m => ({
                            id: m.id,
                            first_name: m.first_name,
                            last_name: m.last_name,
                            checked_in_time: m.checked_in_time || this.formatTime(m.checked_in_at)
                        }));
                        if (window.HeadcountCheckinOffline.setEventCache) window.HeadcountCheckinOffline.setEventCache(this.organizationId, this.eventId, {
                            rsvps: res.rsvps,
                            checkedInIds: res.rsvps.filter(m => m.checked_in).map(m => m.id),
                            lastFetched: Date.now()
                        });
                    } else if (res.success && res.applied > 0) {
                        await this.loadRsvpList();
                    }
                    if (window.HeadcountCheckinOffline.getQueueCount) this.pendingSyncCount = await window.HeadcountCheckinOffline.getQueueCount(this.eventId);
                    this.syncingInProgress = false;
                },
                
                get filteredRsvpList() {
                    const q = (this.rsvpListFilter || '').toLowerCase().trim();
                    if (!q) return this.rsvpList;
                    return this.rsvpList.filter(m => {
                        const name = (m.first_name + ' ' + m.last_name).toLowerCase();
                        const email = (m.email || '').toLowerCase();
                        return name.includes(q) || email.includes(q);
                    });
                },

                get rsvpTableColspan() {
                    let cols = 4;
                    if (this.isPaidEvent) cols++;
                    if (this.hasEventQuestions) cols++;
                    return cols;
                },

                sumRsvpYesHeads(list) {
                    return (list || []).reduce((s, m) => {
                        if (String(m.rsvp_status || '').toLowerCase() !== 'yes') return s;
                        const g = parseInt(m.guest_count, 10) || 0;
                        return s + 1 + g;
                    }, 0);
                },
                sumRsvpYesHeadsPending(list) {
                    return (list || []).reduce((s, m) => {
                        if (String(m.rsvp_status || '').toLowerCase() !== 'yes') return s;
                        if (m.checked_in) return s;
                        const g = parseInt(m.guest_count, 10) || 0;
                        return s + 1 + g;
                    }, 0);
                },
                
                async loadRsvpList() {
                    if (this.isOffline) return;
                    this.loadingRsvpList = true;
                    try {
                        const res = await fetch(`${this.apiBase}/checkin-rsvps.php?event_id=${this.eventId}`, { credentials: 'same-origin' });
                        const data = await res.json();
                        if (data.success) {
                            this.rsvpList = data.rsvps || [];
                            this.totalRsvps = data.total_heads != null ? data.total_heads : this.sumRsvpYesHeads(data.rsvps || []);
                            if (data.total_registrants_yes != null) {
                                this.registrantYesCount = data.total_registrants_yes;
                            } else {
                                this.registrantYesCount = (data.rsvps || []).filter(m => String(m.rsvp_status || '').toLowerCase() === 'yes').length;
                            }
                            this.checkedInCount = data.checked_in || 0;
                            this.pendingCount = data.not_checked_in_heads != null ? data.not_checked_in_heads : this.sumRsvpYesHeadsPending(data.rsvps || []);
                            if (window.HeadcountCheckinOffline && window.HeadcountCheckinOffline.setEventCache) {
                                window.HeadcountCheckinOffline.setEventCache(this.organizationId, this.eventId, {
                                    rsvps: data.rsvps || [],
                                    checkedInIds: (data.rsvps || []).filter(m => m.checked_in).map(m => m.id),
                                    lastFetched: Date.now()
                                });
                            }
                        }
                    } catch (e) {
                        console.error('Load RSVP list error:', e);
                    } finally {
                        this.loadingRsvpList = false;
                    }
                },
                
                saveCheckinViewPreference(view) {
                    localStorage.setItem('checkinViewMode', view);
                },
                
                get totalPages() {
                    return Math.ceil(this.checkedInMembers.length / this.itemsPerPage);
                },
                
                get paginatedMembers() {
                    const start = (this.currentPage - 1) * this.itemsPerPage;
                    const end = start + this.itemsPerPage;
                    return this.checkedInMembers.slice(start, end);
                },
                
                formatTime(dateString) {
                    if (!dateString) return '';
                    const s = String(dateString).trim();
                    const d = new Date(s.includes('T') ? s : s.replace(' ', 'T'));
                    if (Number.isNaN(d.getTime())) return '';
                    return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
                },
                
                toggleQRScanner() {
                    this.showQRScanner = !this.showQRScanner;
                    if (this.showQRScanner) {
                        this.$nextTick(() => {
                            setTimeout(() => {
                                if (typeof window.openQRScanner === 'function') {
                                    window.openQRScanner();
                                }
                            }, 300);
                        });
                    } else {
                        if (typeof window.closeQRScanner === 'function') {
                            window.closeQRScanner();
                        }
                    }
                },
                
                async handleQRCheckIn(userId, userName) {
                    // Use the existing checkIn method
                    await this.checkIn(userId);
                    // Close scanner after successful check-in
                    setTimeout(() => {
                        this.showQRScanner = false;
                        if (typeof window.closeQRScanner === 'function') {
                            window.closeQRScanner();
                        }
                    }, 1500);
                },
                
                async search() {
                    if (this.searchQuery.length < 2) {
                        this.results = [];
                        this.offlineSearchHint = false;
                        return;
                    }
                    this.searching = true;
                    this.offlineSearchHint = false;
                    if (this.isOffline && this.rsvpList.length) {
                        const q = this.searchQuery.toLowerCase().trim();
                        this.results = this.rsvpList.filter(m => {
                            const name = (m.first_name + ' ' + m.last_name).toLowerCase();
                            const email = (m.email || '').toLowerCase();
                            const phone = (m.phone || '').replace(/\D/g, '');
                            const qNum = q.replace(/\D/g, '');
                            return name.includes(q) || email.includes(q) || (qNum.length >= 3 && phone.includes(qNum));
                        });
                        this.offlineSearchHint = true;
                        this.searching = false;
                        return;
                    }
                    try {
                        const response = await fetch(`${this.apiBase}/search-members.php?q=${encodeURIComponent(this.searchQuery)}&event_id=${this.eventId}`, { credentials: 'same-origin' });
                        const data = await response.json();
                        this.results = data.members || [];
                    } catch (error) {
                        console.error('Search error:', error);
                    } finally {
                        this.searching = false;
                    }
                },
                
                async checkIn(userId) {
                    const member = this.results.find(m => m.id === userId) || this.rsvpList.find(m => m.id === userId);
                    const guestCount = (member && (member.guest_count !== undefined && member.guest_count !== null)) ? parseInt(member.guest_count, 10) : 0;
                    if (member && guestCount > 0) {
                        this.guestCountModalUserId = userId;
                        this.guestCountModalMemberName = (member.first_name || '') + ' ' + (member.last_name || '');
                        this.guestCountModalValue = guestCount;
                        this.showGuestCountModal = true;
                        return;
                    }
                    await this.doCheckIn(userId, 0);
                },
                async doCheckIn(userId, guestsCheckedIn) {
                    const Offline = window.HeadcountCheckinOffline;
                    if (this.isOffline && Offline && Offline.enqueueAction) {
                        const clientTs = new Date().toISOString();
                        await Offline.enqueueAction(this.organizationId, this.eventId, 'checkin', userId, clientTs, guestsCheckedIn);
                        const member = this.results.find(m => m.id === userId) || this.rsvpList.find(m => m.id === userId);
                        const checkedInTime = new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
                        if (member) {
                            member.checked_in = true;
                            member.checked_in_time = checkedInTime;
                            this.checkedInMembers.unshift({
                                id: member.id,
                                first_name: member.first_name,
                                last_name: member.last_name,
                                checked_in_time: checkedInTime
                            });
                        }
                        this.checkedInCount++;
                        const heads = 1 + (parseInt(guestsCheckedIn, 10) || 0);
                        this.pendingCount = Math.max(0, this.pendingCount - heads);
                        this.currentPage = 1;
                        this.pendingSyncCount = await (Offline.getQueueCount ? Offline.getQueueCount(this.eventId) : 0);
                        this.successMessage = '✓ Check-in recorded (will sync when online)';
                        this.showSuccess = true;
                        setTimeout(() => { this.showSuccess = false; }, 3000);
                        setTimeout(() => { this.searchQuery = ''; this.results = []; }, 2000);
                        return;
                    }
                    try {
                        const response = await fetch(`${this.apiBase}/checkin.php`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({
                                event_id: this.eventId,
                                user_id: userId,
                                guests_checked_in: guestsCheckedIn
                            })
                        });
                        
                        let data;
                        try {
                            data = await response.json();
                        } catch (jsonError) {
                            console.error('Failed to parse JSON response:', jsonError);
                            const text = await response.text();
                            console.error('Response text:', text);
                            data = {
                                success: false,
                                message: response.status === 400 
                                    ? 'Check-in request was invalid. Please try again.' 
                                    : 'An error occurred. Please try again.'
                            };
                        }
                        
                        if (!data.success) {
                            console.log('Check-in failed:', { status: response.status, message: data.message, eventId: this.eventId, userId: userId });
                        }
                        
                        if (data.success) {
                            const guestsHeads = 1 + (parseInt(guestsCheckedIn, 10) || 0);
                            const member = this.results.find(m => m.id === userId) || this.rsvpList.find(m => m.id === userId);
                            if (member) {
                                member.checked_in = true;
                                member.checked_in_time = data.checked_in_time || this.formatTime(data.checked_in_at);
                                const existingIdx = this.checkedInMembers.findIndex(m => m.id === userId);
                                const entry = {
                                    id: member.id,
                                    first_name: member.first_name,
                                    last_name: member.last_name,
                                    checked_in_time: data.checked_in_time || this.formatTime(data.checked_in_at)
                                };
                                if (existingIdx >= 0) {
                                    this.checkedInMembers[existingIdx] = entry;
                                } else {
                                    this.checkedInMembers.unshift(entry);
                                    this.checkedInCount += guestsHeads;
                                }
                                this.pendingCount = Math.max(0, this.pendingCount - guestsHeads);
                                this.currentPage = 1;
                            }
                            await this.loadRsvpList();
                            this.successMessage = `✓ ${data.member_name} checked in!`;
                            this.showSuccess = true;
                            setTimeout(() => { this.showSuccess = false; }, 3000);
                            setTimeout(() => { this.searchQuery = ''; this.results = []; }, 2000);
                        } else if (this.canCorrectCheckins && this.isLiveCheckinBlocked(data.message)) {
                            const member = this.results.find(m => m.id === userId) || this.rsvpList.find(m => m.id === userId);
                            const useOverride = await confirmAction({
                                title: 'Outside check-in window',
                                message: (data.message || 'Live check-in is not available right now.') + ' Record attendance with a logged correction instead?',
                                type: 'warning',
                                okText: 'Record attendance',
                                cancelText: 'Cancel',
                                showCancel: true
                            });
                            if (useOverride && member) {
                                this.openCorrectionModalForMember(member, 'checkin');
                            }
                        } else {
                            await confirmAction({
                                title: 'Check-In Not Allowed',
                                message: data.message || 'Failed to check in. Please try again.',
                                type: 'warning',
                                okText: 'OK',
                                showCancel: false
                            });
                        }
                    } catch (error) {
                        console.error('Check-in error:', error);
                        await confirmAction({
                            title: 'Check-In Error',
                            message: 'Failed to check in. Please try again.',
                            type: 'danger',
                            okText: 'OK',
                            showCancel: false
                        });
                    }
                },
                async confirmGuestCountAndCheckIn() {
                    const userId = this.guestCountModalUserId;
                    const val = Math.max(0, Math.min(20, parseInt(this.guestCountModalValue, 10) || 0));
                    this.showGuestCountModal = false;
                    this.guestCountModalUserId = null;
                    this.guestCountModalMemberName = '';
                    this.guestCountModalValue = 0;
                    if (userId) await this.doCheckIn(userId, val);
                },
                isLiveCheckinBlocked(message) {
                    const m = String(message || '').toLowerCase();
                    return m.includes('only allowed between') || m.includes('check-in window') || m.includes('check-in opens') || m.includes('same day') || m.includes('not allowed');
                },
                defaultCorrectionDateTime(checkedInAt) {
                    if (checkedInAt) {
                        const normalized = String(checkedInAt).trim().replace(' ', 'T');
                        const d = new Date(normalized);
                        if (!isNaN(d.getTime())) {
                            const pad = (n) => String(n).padStart(2, '0');
                            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate())
                                + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
                        }
                    }
                    const date = this.eventDate || '';
                    let time = this.eventStartTime || '12:00:00';
                    if (time.length === 5) time += ':00';
                    return date + 'T' + time.slice(0, 5);
                },
                openCorrectionModalForMember(member, action) {
                    if (!member || !this.canCorrectCheckins) return;
                    const name = ((member.first_name || '') + ' ' + (member.last_name || '')).trim();
                    const guests = member.guest_count != null ? parseInt(member.guest_count, 10) || 0 : 0;
                    this.openCorrectionModal(action, member.id, name, member.checked_in_at, guests);
                },
                openCorrectionModal(action, userId, userName, checkedInAt, guestsCheckedIn) {
                    this.correctionForm = {
                        action: action,
                        user_id: userId,
                        user_name: userName || '',
                        reason: '',
                        checked_in_at_local: action === 'undo' ? '' : this.defaultCorrectionDateTime(checkedInAt || null),
                        guests_checked_in: guestsCheckedIn != null ? parseInt(guestsCheckedIn, 10) || 0 : 0,
                    };
                    this.showCorrectionModal = true;
                },
                closeCorrectionModal() {
                    this.showCorrectionModal = false;
                    this.correctionSaving = false;
                },
                async submitCorrection() {
                    if (!this.canCorrectCheckins || !this.correctionForm.user_id) return;
                    const reason = (this.correctionForm.reason || '').trim();
                    if (reason.length < 3) {
                        alert('Please enter a reason (at least 3 characters).');
                        return;
                    }
                    const payload = {
                        event_id: this.eventId,
                        user_id: this.correctionForm.user_id,
                        action: this.correctionForm.action,
                        reason: reason,
                    };
                    if (this.correctionForm.action !== 'undo') {
                        const local = this.correctionForm.checked_in_at_local;
                        if (!local) {
                            alert('Please set a check-in time.');
                            return;
                        }
                        payload.checked_in_at = local.length === 16 ? local.replace('T', ' ') + ':00' : local.replace('T', ' ');
                        payload.guests_checked_in = this.correctionForm.guests_checked_in;
                    }
                    this.correctionSaving = true;
                    try {
                        const r = await fetch(this.apiBase.replace(/\/+$/, '') + '/checkin-override.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload),
                            credentials: 'same-origin',
                        });
                        const data = await r.json().catch(() => ({ success: false }));
                        if (data.success) {
                            this.closeCorrectionModal();
                            await this.loadRsvpList();
                            this.searchQuery = '';
                            this.results = [];
                            this.successMessage = 'Attendance correction saved.';
                            this.showSuccess = true;
                            setTimeout(() => { this.showSuccess = false; }, 3000);
                        } else {
                            alert(data.message || 'Could not save correction.');
                        }
                    } catch (e) {
                        alert('An error occurred while saving.');
                    }
                    this.correctionSaving = false;
                },
                
                async undoCheckIn(userId) {
                    const member = this.checkedInMembers.find(m => m.id === userId) || this.results.find(m => m.id === userId) || this.rsvpList.find(m => m.id === userId);
                    const memberName = member ? `${member.first_name} ${member.last_name}` : 'this member';
                    
                    const confirmed = await confirmAction({
                        title: 'Undo Check-In',
                        message: `Are you sure you want to undo the check-in for ${memberName}? This action cannot be undone.`,
                        type: 'danger',
                        okText: 'Yes, Undo Check-In',
                        cancelText: 'Cancel'
                    });
                    
                    if (!confirmed) return;
                    
                    const Offline = window.HeadcountCheckinOffline;
                    if (this.isOffline && Offline && Offline.enqueueAction) {
                        await Offline.enqueueAction(this.organizationId, this.eventId, 'undo', userId);
                        const resultMember = this.results.find(m => m.id === userId) || this.rsvpList.find(m => m.id === userId);
                        if (resultMember) { resultMember.checked_in = false; resultMember.checked_in_time = null; }
                        this.checkedInMembers = this.checkedInMembers.filter(m => m.id !== userId);
                        this.checkedInCount--;
                        const g = resultMember ? (parseInt(resultMember.guest_count, 10) || 0) : 0;
                        this.pendingCount += 1 + g;
                        if (this.currentPage > this.totalPages && this.totalPages > 0) this.currentPage = this.totalPages;
                        this.pendingSyncCount = await (Offline.getQueueCount ? Offline.getQueueCount(this.eventId) : 0);
                        this.successMessage = '✓ Undo recorded (will sync when online)';
                        this.showSuccess = true;
                        setTimeout(() => { this.showSuccess = false; }, 3000);
                        return;
                    }
                    
                    try {
                        const response = await fetch(`${this.apiBase}/undo-checkin.php`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ event_id: this.eventId, user_id: userId }),
                            credentials: 'same-origin'
                        });
                        const data = await response.json();
                        if (data.success) {
                            const resultMember = this.results.find(m => m.id === userId);
                            if (resultMember) { resultMember.checked_in = false; resultMember.checked_in_time = null; }
                            this.checkedInMembers = this.checkedInMembers.filter(m => m.id !== userId);
                            this.checkedInCount--;
                            this.loadRsvpList();
                            if (this.currentPage > this.totalPages && this.totalPages > 0) this.currentPage = this.totalPages;
                            this.successMessage = `✓ ${memberName}'s check-in has been undone`;
                            this.showSuccess = true;
                            setTimeout(() => { this.showSuccess = false; }, 3000);
                        } else {
                            alert(data.message || 'Failed to undo check-in. Please try again.');
                        }
                    } catch (error) {
                        console.error('Undo error:', error);
                        alert('Failed to undo check-in. Please try again.');
                    }
                },
                
                async recordCashPayment(member) {
                    if (this.isOffline) {
                        alert('Cash payment is available when online.');
                        return;
                    }
                    const amount = parseFloat(member.cashAmount);
                    if (!amount || amount <= 0) {
                        alert('Enter a valid amount.');
                        return;
                    }
                    try {
                        const res = await fetch(`${this.apiBase}/cash-payment.php`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'create',
                                event_id: this.eventId,
                                user_id: member.id,
                                amount: amount
                            }),
                            credentials: 'same-origin'
                        });
                        const data = await res.json();
                        if (data.success) {
                            member.payment_id = data.payment_id;
                            member.payment_amount = data.amount;
                            member.payment_method = 'cash';
                            member.cashAmount = '';
                            this.successMessage = 'Cash payment recorded.';
                            this.showSuccess = true;
                            setTimeout(() => { this.showSuccess = false; }, 2000);
                        } else {
                            alert(data.message || 'Failed to record cash payment.');
                        }
                    } catch (e) {
                        console.error(e);
                        alert('Failed to record cash payment.');
                    }
                },
                
                toggleCashEdit(member) {
                    if (!member.cashEditing) {
                        member.cashEditing = true;
                        member.cashEditAmount = member.payment_amount;
                    } else {
                        this.saveCashEdit(member);
                    }
                },
                
                async saveCashEdit(member) {
                    if (this.isOffline) { alert('Cash payment is available when online.'); return; }
                    const amount = parseFloat(member.cashEditAmount);
                    if (!amount || amount <= 0) {
                        alert('Enter a valid amount.');
                        return;
                    }
                    try {
                        const res = await fetch(`${this.apiBase}/cash-payment.php`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                action: 'update',
                                payment_id: member.payment_id,
                                amount: amount
                            }),
                            credentials: 'same-origin'
                        });
                        const data = await res.json();
                        if (data.success) {
                            member.payment_amount = data.amount;
                            member.cashEditing = false;
                            this.successMessage = 'Cash payment updated.';
                            this.showSuccess = true;
                            setTimeout(() => { this.showSuccess = false; }, 2000);
                        } else {
                            alert(data.message || 'Failed to update.');
                        }
                    } catch (e) {
                        console.error(e);
                        alert('Failed to update cash payment.');
                    }
                },
                
                async deleteCashPayment(member) {
                    if (this.isOffline) { alert('Cash payment is available when online.'); return; }
                    if (!member.payment_id || (member.payment_method || '').toLowerCase() !== 'cash') return;
                    if (!confirm('Delete this cash payment? This cannot be undone.')) return;
                    try {
                        const res = await fetch(`${this.apiBase}/cash-payment.php`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'delete', payment_id: member.payment_id }),
                            credentials: 'same-origin'
                        });
                        const data = await res.json();
                        if (data.success) {
                            member.payment_id = null;
                            member.payment_amount = null;
                            member.payment_method = null;
                            this.successMessage = 'Cash payment deleted.';
                            this.showSuccess = true;
                            setTimeout(() => { this.showSuccess = false; }, 2000);
                        } else {
                            alert(data.message || 'Failed to delete.');
                        }
                    } catch (e) {
                        console.error(e);
                        alert('Failed to delete cash payment.');
                    }
                },
                
                async addNewMember() {
                    if (this.isOffline) {
                        this.addMemberError = 'Adding members is available when online.';
                        return;
                    }
                    this.addingMember = true;
                    this.addMemberError = '';
                    
                    try {
                        const response = await fetch(`${this.apiBase}/members.php`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                                'X-CSRF-Token': csrfToken
                            },
                            credentials: 'same-origin',
                            body: JSON.stringify({
                                ...this.newMember,
                                csrf_token: csrfToken
                            })
                        });
                        
                        const data = await response.json();
                        
                        if (data.success) {
                            // Close modal
                            this.showAddMember = false;
                            
                            // Store member name for success message
                            const memberName = `${this.newMember.first_name} ${this.newMember.last_name}`;
                            
                            // Reset form
                            this.newMember = {
                                first_name: '',
                                last_name: '',
                                email: '',
                                phone: '',
                                gender: ''
                            };
                            
                            // Show success message
                            this.successMessage = `✓ ${memberName} added successfully!`;
                            this.showSuccess = true;
                            setTimeout(() => {
                                this.showSuccess = false;
                            }, 3000);
                            
                            // Auto-search for the new member to show them in results
                            const memberId = data.member_id || (data.member && data.member.id);
                            if (memberId) {
                                // Set search query to member's name to find them
                                this.searchQuery = memberName;
                                await this.search();
                                
                                // After a brief delay, try to scroll to the new member if found
                                setTimeout(() => {
                                    const newMemberResult = this.results.find(m => m.id === memberId);
                                    if (newMemberResult) {
                                        // Scroll to the result if needed
                                        const resultElement = document.querySelector(`[data-member-id="${memberId}"]`);
                                        if (resultElement) {
                                            resultElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                        }
                                    }
                                }, 500);
                            } else if (this.searchQuery.length >= 2) {
                                // Fallback: refresh search if query exists
                                await this.search();
                            }
                        } else {
                            this.addMemberError = data.message || (data.errors ? data.errors.join(', ') : 'Failed to add member');
                        }
                    } catch (error) {
                        console.error('Add member error:', error);
                        this.addMemberError = 'Failed to add member. Please try again.';
                    } finally {
                        this.addingMember = false;
                    }
                }
            }
        }
    </script>

    <!-- QR Code Scanner Library - Using jsQR for better screen QR code detection -->
    <script src="https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.min.js"></script>
    <script>
        let videoStream = null;
        let videoElement = null;
        let canvasElement = null;
        let scanInterval = null;
        let isScanning = false;
        let scannerInitialized = false;
        let alpineApp = null;
        let scanProcessed = false;

        // Get Alpine.js app instance
        function getAlpineApp() {
            if (!alpineApp) {
                const appElement = document.querySelector('[x-data*="checkinApp"]');
                if (appElement && appElement.__x) {
                    alpineApp = appElement.__x.$data;
                }
            }
            return alpineApp;
        }

        // Watch for modal visibility using Alpine.js
        document.addEventListener('alpine:init', () => {
            Alpine.effect(() => {
                const app = getAlpineApp();
                if (app) {
                    if (app.showQRScanner && !isScanning && !scannerInitialized) {
                        setTimeout(() => {
                            openQRScanner();
                        }, 300);
                    } else if (!app.showQRScanner && isScanning) {
                        closeQRScanner();
                    }
                }
            });
        });

        function openQRScanner() {
            const container = document.getElementById('qr-scanner-container');
            const statusDiv = document.getElementById('qr-scan-status');
            const resultDiv = document.getElementById('qr-scan-result');
            
            if (!container || !statusDiv) {
                console.error('QR scanner elements not found');
                return;
            }
            
            if (isScanning) {
                return; // Already scanning
            }
            
            // Wait for modal to be visible
            const waitForVisible = () => {
                return new Promise((resolve) => {
                    let attempts = 0;
                    const maxAttempts = 50;
                    
                    const checkVisible = () => {
                        attempts++;
                        const modal = document.querySelector('[x-show*="showQRScanner"]');
                        
                        if (!modal) {
                            if (attempts >= maxAttempts) {
                                resolve();
                                return;
                            }
                            setTimeout(checkVisible, 50);
                            return;
                        }
                        
                        const modalStyle = window.getComputedStyle(modal);
                        const containerRect = container.getBoundingClientRect();
                        const isModalVisible = modalStyle.display !== 'none' && !modal.hasAttribute('x-cloak');
                        const hasDimensions = containerRect.width > 0 && containerRect.height > 0;
                        
                        if (isModalVisible && hasDimensions) {
                            resolve();
                        } else if (attempts >= maxAttempts) {
                            resolve();
                        } else {
                            requestAnimationFrame(checkVisible);
                        }
                    };
                    checkVisible();
                });
            };
            
            waitForVisible().then(() => {
                startQRScanner(container, statusDiv, resultDiv);
            }).catch(() => {
                startQRScanner(container, statusDiv, resultDiv);
            });
        }
        
        function startQRScanner(container, statusDiv, resultDiv) {
            // Reset scan state
            scanProcessed = false;
            statusDiv.textContent = 'Loading scanner...';
            
            // Wait for jsQR library to load
            const waitForLibrary = () => {
                return new Promise((resolve, reject) => {
                    if (typeof jsQR !== 'undefined') {
                        resolve();
                        return;
                    }
                    
                    let attempts = 0;
                    const maxAttempts = 50;
                    
                    const checkLibrary = () => {
                        attempts++;
                        if (typeof jsQR !== 'undefined') {
                            resolve();
                        } else if (attempts >= maxAttempts) {
                            reject(new Error('jsQR library failed to load'));
                        } else {
                            setTimeout(checkLibrary, 100);
                        }
                    };
                    
                    checkLibrary();
                });
            };
            
            waitForLibrary().then(() => {
                startScannerInternal(container, statusDiv, resultDiv);
            }).catch((err) => {
                console.error('Library load error:', err);
                statusDiv.innerHTML = '<span class="text-red-600">QR scanner library failed to load. Please refresh the page.</span>';
                isScanning = false;
                scannerInitialized = false;
            });
        }
        
        function startScannerInternal(container, statusDiv, resultDiv) {
            statusDiv.textContent = 'Position QR code within the frame';
            if (resultDiv) {
                resultDiv.classList.add('hidden');
            }
            
            scanProcessed = false;
            isScanning = true;
            scannerInitialized = true;
            
            const eventId = <?php echo json_encode($eventId); ?>;
            const apiBase = <?php echo json_encode($baseUrl . '/api/portal/'); ?>;
            
            // Clear container and remove loading indicator
            const loadingDiv = document.getElementById('qr-scanner-loading');
            if (loadingDiv) {
                loadingDiv.remove();
            }
            container.innerHTML = '';
            
            // Set container dimensions
            const containerRect = container.getBoundingClientRect();
            const width = containerRect.width > 0 ? containerRect.width : 500;
            const height = containerRect.height > 0 ? containerRect.height : 500;
            
            container.style.position = 'relative';
            container.style.overflow = 'hidden';
            container.style.width = width + 'px';
            container.style.height = height + 'px';
            container.style.minWidth = '300px';
            container.style.minHeight = '300px';
            container.style.maxWidth = '100%';
            container.style.display = 'block';
            container.style.border = '2px solid #10b981';
            
            // Create video element
            videoElement = document.createElement('video');
            videoElement.style.width = '100%';
            videoElement.style.height = '100%';
            videoElement.style.objectFit = 'contain';
            videoElement.style.display = 'block';
            videoElement.setAttribute('playsinline', 'true');
            videoElement.setAttribute('autoplay', 'true');
            videoElement.setAttribute('muted', 'true');
            
            // Create canvas element for processing
            canvasElement = document.createElement('canvas');
            canvasElement.style.display = 'none';
            
            container.appendChild(videoElement);
            container.appendChild(canvasElement);
            
            // Try to use BarcodeDetector API first (faster and more reliable)
            if ('BarcodeDetector' in window) {
                console.log('Using native BarcodeDetector API');
                startBarcodeDetector(videoElement, canvasElement, container, statusDiv, eventId, apiBase);
            } else {
                console.log('BarcodeDetector not available, using jsQR');
                startJsQRScanner(videoElement, canvasElement, container, statusDiv, eventId, apiBase);
            }
        }
        
        function startBarcodeDetector(video, canvas, container, statusDiv, eventId, apiBase) {
            const barcodeDetector = new BarcodeDetector({ formats: ['qr_code'] });
            
            navigator.mediaDevices.getUserMedia({ 
                video: { 
                    facingMode: "environment",
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                } 
            })
            .then((stream) => {
                videoStream = stream;
                video.srcObject = stream;
                
                video.onloadedmetadata = () => {
                    video.play().then(() => {
                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;
                        
                        statusDiv.innerHTML = '<span class="text-green-600">✓ Camera active</span><br><span class="text-sm text-gray-600 mt-1 block dark:text-gray-300">Tips: Make QR code large on phone screen • Hold phone steady • Ensure good lighting • Keep 6-12 inches away</span>';
                        
                        // Start scanning loop
                        const ctx = canvas.getContext('2d', { willReadFrequently: true });
                        scanInterval = setInterval(() => {
                            if (!isScanning || scanProcessed) return;
                            
                            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                            
                            barcodeDetector.detect(canvas)
                                .then((barcodes) => {
                                    if (barcodes.length > 0 && !scanProcessed) {
                                        const qrCode = barcodes[0].rawValue;
                                        console.log('QR Code detected via BarcodeDetector:', qrCode);
                                        scanProcessed = true;
                                        stopScanning();
                                        handleQRCodeScanned(qrCode, eventId, apiBase);
                                    }
                                })
                                .catch((err) => {
                                    // Silently handle detection errors
                                });
                        }, 200); // Scan every 200ms
                    }).catch((err) => {
                        console.error("Error playing video:", err);
                        statusDiv.innerHTML = '<span class="text-red-600">Unable to start camera. Please try again.</span>';
                        isScanning = false;
                        scannerInitialized = false;
                    });
                };
            })
            .catch((err) => {
                console.error("Camera permission denied or error:", err);
                let errorMsg = 'Unable to access camera. ';
                if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                    errorMsg += 'Please allow camera permissions and try again.';
                } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                    errorMsg += 'No camera found.';
                } else {
                    errorMsg += 'Error: ' + (err.message || err.name || 'Unknown error');
                }
                statusDiv.innerHTML = '<span class="text-red-600">' + errorMsg + '</span>';
                isScanning = false;
                scannerInitialized = false;
            });
        }
        
        function startJsQRScanner(video, canvas, container, statusDiv, eventId, apiBase) {
            navigator.mediaDevices.getUserMedia({ 
                video: { 
                    facingMode: "environment",
                    width: { ideal: 1280 },
                    height: { ideal: 720 }
                } 
            })
            .then((stream) => {
                videoStream = stream;
                video.srcObject = stream;
                
                video.onloadedmetadata = () => {
                    video.play().then(() => {
                        canvas.width = video.videoWidth;
                        canvas.height = video.videoHeight;
                        
                        statusDiv.innerHTML = '<span class="text-green-600">✓ Camera active</span><br><span class="text-sm text-gray-600 mt-1 block dark:text-gray-300">Tips: Make QR code large on phone screen • Hold phone steady • Ensure good lighting • Keep 6-12 inches away</span>';
                        
                        // Start scanning loop
                        const ctx = canvas.getContext('2d', { willReadFrequently: true });
                        scanInterval = setInterval(() => {
                            if (!isScanning || scanProcessed) return;
                            
                            ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                            
                            const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height);
                            const code = jsQR(imageData.data, imageData.width, imageData.height, {
                                inversionAttempts: "dontInvert",
                            });
                            
                            if (code && !scanProcessed) {
                                console.log('QR Code detected via jsQR:', code.data);
                                scanProcessed = true;
                                stopScanning();
                                handleQRCodeScanned(code.data, eventId, apiBase);
                            }
                        }, 200); // Scan every 200ms
                    }).catch((err) => {
                        console.error("Error playing video:", err);
                        statusDiv.innerHTML = '<span class="text-red-600">Unable to start camera. Please try again.</span>';
                        isScanning = false;
                        scannerInitialized = false;
                    });
                };
            })
            .catch((err) => {
                console.error("Camera permission denied or error:", err);
                let errorMsg = 'Unable to access camera. ';
                if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
                    errorMsg += 'Please allow camera permissions and try again.';
                } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
                    errorMsg += 'No camera found.';
                } else {
                    errorMsg += 'Error: ' + (err.message || err.name || 'Unknown error');
                }
                statusDiv.innerHTML = '<span class="text-red-600">' + errorMsg + '</span>';
                isScanning = false;
                scannerInitialized = false;
            });
        }
        
        function stopScanning() {
            if (scanInterval) {
                clearInterval(scanInterval);
                scanInterval = null;
            }
            
            if (videoStream) {
                videoStream.getTracks().forEach(track => track.stop());
                videoStream = null;
            }
            
            if (videoElement) {
                videoElement.srcObject = null;
                videoElement = null;
            }
            
            isScanning = false;
        }

        function closeQRScanner() {
            stopScanning();
            scannerInitialized = false;
            scanProcessed = false;
        }
        
        // Function to check in family member
        async function checkInFamilyMember(familyMemberId, familyMemberName) {
            const statusDiv = document.getElementById('qr-scan-status');
            const resultContent = document.getElementById('qr-result-content');
            const eventId = <?php echo json_encode($eventId); ?>;
            const apiBase = <?php echo json_encode($baseUrl . '/api/portal/'); ?>;
            
            statusDiv.innerHTML = '<span class="text-brand-600">Processing family member check-in...</span>';
            
            try {
                const response = await fetch(apiBase + 'checkin', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        qr_code: '', // Will be handled by user context
                        event_id: eventId,
                        family_member_id: familyMemberId
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    resultContent.innerHTML = `
                        <div class="text-center py-4">
                            <div class="text-green-600 font-bold mb-2">✓ Family Member Checked In!</div>
                            <div class="text-gray-700 dark:text-gray-200">${familyMemberName}</div>
                        </div>
                    `;
                    statusDiv.innerHTML = '<span class="text-green-600">✓ Family member checked in</span>';
                    
                    // Update Alpine.js app state
                    const app = getAlpineApp();
                    if (app) {
                        // Use first_name/last_name from API if available, otherwise parse from name
                        const firstName = data.user.first_name || familyMemberName.split(' ')[0] || '';
                        const lastName = data.user.last_name || familyMemberName.split(' ').slice(1).join(' ') || '';
                        const checkedInTime = data.checked_in_time || new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
                        
                        // Check if member is already in the list (avoid duplicates)
                        const existingIndex = app.checkedInMembers.findIndex(m => m.id === (data.user.id || 0));
                        if (existingIndex >= 0) {
                            // Update existing entry
                            app.checkedInMembers[existingIndex] = {
                                id: data.user.id || 0,
                                first_name: firstName,
                                last_name: lastName,
                                checked_in_time: checkedInTime
                            };
                        } else {
                            // Add new entry at the beginning
                            app.checkedInMembers.unshift({
                                id: data.user.id || 0,
                                first_name: firstName,
                                last_name: lastName,
                                checked_in_time: checkedInTime
                            });
                        }
                        
                        // Update count (only increment if it's a new check-in)
                        if (existingIndex < 0) {
                            app.checkedInCount++;
                        }
                        
                        // Reset to first page to show the newly checked-in member
                        app.currentPage = 1;
                        
                        // Force Alpine.js reactivity update
                        app.$nextTick(() => {
                            console.log('UI updated with family member check-in:', {
                                id: data.user.id || 0,
                                name: familyMemberName
                            });
                        });
                    }
                    
                    // Close scanner after 1.5 seconds
                    setTimeout(() => {
                        const app = getAlpineApp();
                        if (app) {
                            app.showQRScanner = false;
                        }
                        closeQRScanner();
                    }, 1500);
                } else {
                    resultContent.innerHTML = `
                        <div class="text-center py-4">
                            <div class="text-red-600 font-bold mb-2">✗ Check-In Failed</div>
                            <div class="text-gray-700 dark:text-gray-200">${data.message || 'Unknown error'}</div>
                        </div>
                    `;
                    statusDiv.innerHTML = '<span class="text-red-600">✗ Error</span>';
                }
            } catch (error) {
                console.error('Error checking in family member:', error);
                resultContent.innerHTML = `
                    <div class="text-center py-4">
                        <div class="text-red-600 font-bold mb-2">✗ Error</div>
                        <div class="text-gray-700 dark:text-gray-200">Failed to check in family member. Please try again.</div>
                    </div>
                `;
                statusDiv.innerHTML = '<span class="text-red-600">✗ Network error</span>';
            }
        }
        
        // Make functions globally available
        window.openQRScanner = openQRScanner;
        window.closeQRScanner = closeQRScanner;
        window.checkInFamilyMember = checkInFamilyMember;

        async function handleQRCodeScanned(qrCode, eventId, apiBase) {
            console.log('handleQRCodeScanned called with:', { qrCode: qrCode.substring(0, 50) + '...', eventId, apiBase });
            
            const statusDiv = document.getElementById('qr-scan-status');
            const resultDiv = document.getElementById('qr-scan-result');
            const resultContent = document.getElementById('qr-result-content');
            const app = getAlpineApp();
            
            // Scanner should already be stopped by stopScanning(), but ensure it's stopped
            stopScanning();
            
            statusDiv.innerHTML = '<span class="text-green-600 font-medium">✓ QR Code detected! Processing...</span>';
            resultDiv.classList.remove('hidden');
            resultContent.innerHTML = '<div class="text-center py-4">Processing check-in...</div>';
            
            try {
                console.log('Sending QR code to API:', { apiBase, eventId, qrCodeLength: qrCode.length });
                
                const response = await fetch(apiBase + 'checkin', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        qr_code: qrCode,
                        event_id: eventId,
                        csrf_token: csrfToken
                    })
                });
                
                console.log('API response status:', response.status);
                
                // Read response as text first, then try to parse as JSON
                const responseText = await response.text();
                let data;
                try {
                    data = JSON.parse(responseText);
                    console.log('API response data:', data);
                } catch (jsonError) {
                    console.error('Failed to parse JSON response:', jsonError, 'Response text:', responseText);
                    // If it's not JSON (likely HTML error page), create an error response
                    const isHtmlError = responseText.trim().startsWith('<!DOCTYPE') || responseText.trim().startsWith('<html');
                    data = {
                        success: false,
                        message: isHtmlError 
                            ? 'Server error occurred. The API returned an HTML error page instead of JSON. Please check server logs for details.'
                            : response.status === 500 
                            ? 'Server error occurred. Please check server logs.' 
                            : response.status === 404
                            ? 'API endpoint not found'
                            : 'Invalid response from server: ' + responseText.substring(0, 200)
                    };
                }
                
                if (data.success) {
                    if (data.already_checked_in) {
                        const familyNote = data.user.is_family_member && data.user.parent_name 
                            ? ` (${data.user.parent_name})` : '';
                        resultContent.innerHTML = `
                            <div class="text-center py-4">
                                <div class="text-amber-600 font-bold mb-2">⚠  Already Checked In</div>
                                <div class="text-gray-700 dark:text-gray-200">${data.user.name}${familyNote}</div>
                            </div>
                        `;
                        statusDiv.innerHTML = '<span class="text-amber-600">⚠  Already checked in</span>';
                        
                        // Resume scanning after 2 seconds
                        setTimeout(() => {
                            if (app && app.showQRScanner) {
                                scanProcessed = false;
                                openQRScanner();
                            }
                        }, 2000);
                    } else {
                        // Check if family members are available
                        const hasFamilyMembers = data.family_members && data.family_members.length > 0;
                        const familyNote = data.user.is_family_member && data.user.parent_name 
                            ? ` (Family member of ${data.user.parent_name})` : '';
                        
                        if (hasFamilyMembers && !data.user.is_family_member) {
                            // Show family member selection
                            let familyOptions = '<div class="mt-4"><p class="text-sm font-medium mb-2">Also check in family members?</p>';
                            data.family_members.forEach(fm => {
                                familyOptions += `
                                    <button onclick="checkInFamilyMember(${fm.id}, '${fm.first_name} ${fm.last_name}')" 
                                            class="mt-2 btn-primary text-sm py-2 px-4">
                                        Check in ${fm.first_name} ${fm.last_name}
                                    </button>
                                `;
                            });
                            familyOptions += '</div>';
                            
                            resultContent.innerHTML = `
                                <div class="text-center py-4">
                                    <div class="text-green-600 font-bold mb-2">✓ Check-In Successful!</div>
                                    <div class="text-gray-700 mb-4 dark:text-gray-200">${data.user.name}</div>
                                    ${familyOptions}
                                </div>
                            `;
                        } else {
                            resultContent.innerHTML = `
                                <div class="text-center py-4">
                                    <div class="text-green-600 font-bold mb-2">✓ Check-In Successful!</div>
                                    <div class="text-gray-700 dark:text-gray-200">${data.user.name}${familyNote}</div>
                                </div>
                            `;
                        }
                        
                        statusDiv.innerHTML = '<span class="text-green-600">✓ Check-in successful</span>';
                        
                        // Update Alpine.js app state
                        if (app) {
                            // Add to checked-in members list
                            // Use first_name/last_name from API if available, otherwise parse from name
                            const firstName = data.user.first_name || data.user.name.split(' ')[0] || '';
                            const lastName = data.user.last_name || data.user.name.split(' ').slice(1).join(' ') || '';
                            const checkedInTime = data.checked_in_time || new Date().toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
                            
                            // Check if member is already in the list (avoid duplicates)
                            const existingIndex = app.checkedInMembers.findIndex(m => m.id === data.user.id);
                            if (existingIndex >= 0) {
                                // Update existing entry
                                app.checkedInMembers[existingIndex] = {
                                    id: data.user.id,
                                    first_name: firstName,
                                    last_name: lastName,
                                    checked_in_time: checkedInTime
                                };
                            } else {
                                // Add new entry at the beginning
                                app.checkedInMembers.unshift({
                                    id: data.user.id,
                                    first_name: firstName,
                                    last_name: lastName,
                                    checked_in_time: checkedInTime
                                });
                            }
                            
                            // Update count (only increment if it's a new check-in)
                            if (existingIndex < 0) {
                                const guestHeads = 1 + (parseInt(data.guests_checked_in, 10) || 0);
                                app.checkedInCount += guestHeads;
                                app.pendingCount = Math.max(0, (app.pendingCount || 0) - guestHeads);
                            }
                            
                            // Reset to first page to show the newly checked-in member
                            app.currentPage = 1;
                            
                            // Show success message
                            app.successMessage = `✓ ${data.user.name} checked in!`;
                            app.showSuccess = true;
                            setTimeout(() => {
                                app.showSuccess = false;
                            }, 3000);

                            if (typeof app.loadRsvpList === 'function') {
                                app.loadRsvpList();
                            }
                            
                            // Force Alpine.js reactivity update
                            app.$nextTick(() => {
                                console.log('UI updated with new check-in:', {
                                    id: data.user.id,
                                    name: data.user.name,
                                    checkedInCount: app.checkedInCount
                                });
                            });
                        }
                        
                        // Close scanner after 1.5 seconds (unless family members shown)
                        if (!hasFamilyMembers || data.user.is_family_member) {
                            setTimeout(() => {
                                if (app) {
                                    app.showQRScanner = false;
                                }
                                closeQRScanner();
                            }, 1500);
                        }
                    }
                } else {
                    // Show detailed error message
                    const errorMsg = data.message || 'Unknown error';
                    const statusCode = response.status;
                    resultContent.innerHTML = `
                        <div class="text-center py-4">
                            <div class="text-red-600 font-bold mb-2">✗ Check-In Failed</div>
                            <div class="text-gray-700 dark:text-gray-200">${errorMsg}</div>
                            ${statusCode === 500 ? '<div class="text-xs text-gray-500 mt-2 dark:text-gray-400">Server error. Please check server logs for details.</div>' : ''}
                        </div>
                    `;
                    statusDiv.innerHTML = '<span class="text-red-600">✗ Error: ' + errorMsg + '</span>';
                    
                    // Resume scanning after 3 seconds
                    setTimeout(() => {
                        if (app && app.showQRScanner) {
                            scanProcessed = false;
                            openQRScanner();
                        }
                    }, 3000);
                }
            } catch (error) {
                console.error('Error processing QR code:', error);
                resultContent.innerHTML = `
                    <div class="text-center py-4">
                        <div class="text-red-600 font-bold mb-2">✗ Error</div>
                        <div class="text-gray-700 dark:text-gray-200">Failed to process check-in. Please try again.</div>
                    </div>
                `;
                statusDiv.innerHTML = '<span class="text-red-600">✗ Network error</span>';
                
                // Resume scanning after 3 seconds
                setTimeout(() => {
                    if (app && app.showQRScanner) {
                        openQRScanner();
                    }
                }, 3000);
            }
        }
    </script>

</body>
</html>
