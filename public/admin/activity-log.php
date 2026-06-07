<?php

/**
 * Admin Activity Log Page
 * Shows all activities on the site: emails sent, user changes, events, etc.
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Models\ActivityLog;
use Headcount\Helpers\Database;
use Headcount\Helpers\Utilities;
use Headcount\Middleware\AuthMiddleware;

AuthMiddleware::requireAdmin();

$organizationId = AuthMiddleware::getOrganizationId();
$userId = AuthMiddleware::getUserId();
$db = Database::getInstance();

// Get the current user for the header
$userData = $db->queryOne("SELECT first_name, last_name, email FROM users WHERE id = :id", ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email']
] : [
    'name' => 'Administrator',
    'email' => 'admin@headcount.local'
];

$activityLogModel = new ActivityLog();

$actionType = get('action_type', '');
$entityType = get('entity_type', '');
$search = get('search', '');
$dateFrom = get('date_from', '');
$dateTo = get('date_to', '');
$pageNum = max(1, (int) get('p', 1));
$perPage = 50;

// Check if activity_logs table exists
try {
    $tableCheck = $db->query("SHOW TABLES LIKE 'activity_logs'");
    $tableExists = !empty($tableCheck);
} catch (\Exception $e) {
    $tableExists = false;
}

if (!$tableExists) {
    $activities = [];
    $totalCount = 0;
    $stats = [
        'total' => 0,
        'by_type' => [],
        'emails_sent' => 0,
        'user_changes' => 0,
    ];
    $actionTypes = [];
    $filters = [];
} else {
    $filters = [];
    if ($actionType) {
        $filters['action_type'] = $actionType;
    }
    if ($entityType) {
        $filters['entity_type'] = $entityType;
    }
    if ($search) {
        $filters['search'] = $search;
    }
    if ($dateFrom) {
        $filters['date_from'] = $dateFrom;
    }
    if ($dateTo) {
        $filters['date_to'] = $dateTo;
    }

    $totalCount = $activityLogModel->count($organizationId, $filters);
    $offset = ($pageNum - 1) * $perPage;
    $activities = $activityLogModel->getByOrganization($organizationId, $filters, $perPage, $offset);
    $stats = $activityLogModel->getStatistics($organizationId, $filters);

    $actionTypes = $db->query("
        SELECT DISTINCT action_type, COUNT(*) as count
        FROM activity_logs
        WHERE organization_id = :org_id
        GROUP BY action_type
        ORDER BY count DESC
    ", ['org_id' => $organizationId]);
}

$statsFilterActive = $actionType !== '' || $entityType !== '' || $search !== '' || $dateFrom !== '' || $dateTo !== '';
$statsScopeLabel = $statsFilterActive ? 'Matching filters' : 'All time';
$statsEmailLabel = $statsFilterActive ? 'Matching filters' : 'In scope above';

// Calculate base path for assets
if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}
$assetsBase = $basePath . '/public/assets/';

$pageTitle = 'Activity Log';
$currentPage = 'activity-log';
require __DIR__ . '/includes/header.php';
?>

<?php
$pageHeaderTitle = 'Activity Log';
$pageHeaderSubtitle = 'Track all activities on your site: emails sent, user changes, events, and more.';
$pageHeaderActions = '';
require __DIR__ . '/components/page-header.php';
?>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
    <?php
    $statLabel = 'Total Activities';
    $statValue = number_format($stats['total']);
    $statSublabel = $statsScopeLabel;
    $statAccent = 'indigo';
    $statIcon = 'chart';
    require __DIR__ . '/components/stat-card.php';
    $statLabel = 'Emails Sent';
    $statValue = number_format($stats['emails_sent']);
    $statSublabel = $statsEmailLabel;
    $statAccent = 'emerald';
    $statIcon = 'mail';
    require __DIR__ . '/components/stat-card.php';
    $statLabel = 'User Changes';
    $statValue = number_format($stats['user_changes']);
    $statSublabel = $statsScopeLabel;
    $statAccent = 'amber';
    $statIcon = 'users';
    require __DIR__ . '/components/stat-card.php';
    $statLabel = 'This page';
    $statValue = number_format(count($activities));
    $statSublabel = 'of ' . number_format($totalCount) . ' matching';
    $statAccent = 'sky';
    $statIcon = 'layers';
    require __DIR__ . '/components/stat-card.php';
    ?>
</div>

<!-- Filters -->
<div class="bento-card admin-filter-card mb-8">
    <form method="GET" action="<?= e($adminBase . '/?page=activity-log') ?>" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
        <div>
            <label class="mb-2 block text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-slate-400">Action Type</label>
            <select name="action_type" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm text-gray-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                <option value="">All Actions</option>
                <?php foreach ($actionTypes as $type): ?>
                    <option value="<?= e($type['action_type']) ?>" <?= $actionType === $type['action_type'] ? 'selected' : '' ?>>
                        <?= e(ucfirst(str_replace('_', ' ', $type['action_type']))) ?> (<?= $type['count'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div>
            <label class="mb-2 block text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-slate-400">Entity Type</label>
            <select name="entity_type" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm text-gray-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
                <option value="">All Entities</option>
                <option value="user" <?= $entityType === 'user' ? 'selected' : '' ?>>Users</option>
                <option value="event" <?= $entityType === 'event' ? 'selected' : '' ?>>Events</option>
                <option value="email" <?= $entityType === 'email' ? 'selected' : '' ?>>Emails</option>
                <option value="payment" <?= $entityType === 'payment' ? 'selected' : '' ?>>Payments</option>
                <option value="attendance" <?= $entityType === 'attendance' ? 'selected' : '' ?>>Attendance</option>
            </select>
        </div>
        
        <div>
            <label class="mb-2 block text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-slate-400">Date From</label>
            <input type="date" name="date_from" value="<?= e($dateFrom) ?>" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm text-gray-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
        </div>
        
        <div>
            <label class="mb-2 block text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-slate-400">Date To</label>
            <input type="date" name="date_to" value="<?= e($dateTo) ?>" class="w-full rounded-xl border border-gray-200 px-4 py-2.5 text-sm text-gray-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
        </div>
        
        <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary flex-1">Filter</button>
            <a href="<?= e($adminBase . '/?page=activity-log') ?>" class="btn-secondary px-4">Reset</a>
        </div>
    </form>
    
    <form method="GET" action="<?= e($adminBase . '/?page=activity-log') ?>" class="mt-4">
        <input type="hidden" name="action_type" value="<?= e($actionType) ?>">
        <input type="hidden" name="entity_type" value="<?= e($entityType) ?>">
        <input type="hidden" name="date_from" value="<?= e($dateFrom) ?>">
        <input type="hidden" name="date_to" value="<?= e($dateTo) ?>">
        <div class="relative">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search activities..." class="w-full rounded-xl border border-gray-200 px-4 py-2.5 pl-10 text-sm text-gray-900 outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 dark:border-slate-600 dark:bg-slate-900 dark:text-slate-100">
            <svg class="absolute left-3 top-1/2 h-5 w-5 -translate-y-1/2 transform text-gray-400 dark:text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
        </div>
    </form>
</div>

<!-- Activity Log Table -->
<div class="bento-card overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="border-b border-gray-200 bg-gray-50 dark:border-slate-600 dark:bg-slate-800/80">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-slate-400">Time</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-slate-400">User</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-slate-400">Action</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-slate-400">Description</th>
                    <th class="px-6 py-3 text-left text-xs font-semibold uppercase tracking-wider text-gray-600 dark:text-slate-400">Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-slate-700">
                <?php if (!$tableExists): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                            <div class="flex flex-col items-center">
                                <svg class="w-12 h-12 text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
                                <p class="text-sm font-medium">Activity Log Table Not Found</p>
                                <p class="mt-1 text-xs text-gray-400 dark:text-slate-500">Please run the migration: <code class="rounded bg-gray-100 px-2 py-1 dark:bg-slate-800 dark:text-slate-200">007_create_activity_logs_table.sql</code></p>
                            </div>
                        </td>
                    </tr>
                <?php elseif (empty($activities)): ?>
                    <tr>
                        <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                            <div class="flex flex-col items-center">
                                <svg class="w-12 h-12 text-gray-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                <p class="text-sm font-medium">No activities found</p>
                                <p class="text-xs text-gray-400 mt-1">Try adjusting your filters</p>
                            </div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($activities as $activity): ?>
                        <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-slate-800/40">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900"><?= formatDateTime($activity['created_at']) ?></div>
                                <div class="text-xs text-gray-500"><?= timeAgo($activity['created_at']) ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($activity['user_id']): ?>
                                    <div class="text-sm font-medium text-gray-900">
                                        <?= e(trim(($activity['first_name'] ?? '') . ' ' . ($activity['last_name'] ?? '')) ?: 'Unknown User') ?>
                                    </div>
                                    <?php if ($activity['user_email']): ?>
                                        <div class="text-xs text-gray-500"><?= e($activity['user_email']) ?></div>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-sm text-gray-400 italic">System</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                                    <?php
                                    $actionType = $activity['action_type'];
                                    if (strpos($actionType, 'email') !== false) {
                                        echo 'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/45 dark:text-indigo-200';
                                    } elseif (strpos($actionType, 'user') !== false) {
                                        echo 'bg-green-100 text-green-800 dark:bg-emerald-900/40 dark:text-emerald-200';
                                    } elseif (strpos($actionType, 'event') !== false) {
                                        echo 'bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200';
                                    } elseif (strpos($actionType, 'checkin') !== false || strpos($actionType, 'payment') !== false) {
                                        echo 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200';
                                    } else {
                                        echo 'bg-gray-100 text-gray-800 dark:bg-slate-700 dark:text-slate-200';
                                    }
                                    ?>">
                                    <?= e(ucfirst(str_replace('_', ' ', $actionType))) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-gray-900"><?= e($activity['description']) ?></div>
                                <?php if ($activity['entity_type'] && $activity['entity_id']): ?>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <?= e(ucfirst($activity['entity_type'])) ?> #<?= $activity['entity_id'] ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php if ($activity['metadata'] || $activity['ip_address'] || $activity['user_agent']): ?>
                                    <button 
                                        type="button"
                                        onclick="document.getElementById('modal-<?= $activity['id'] ?>').showModal()"
                                        class="cursor-pointer text-xs font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                    >
                                        View Details
                                    </button>
                                    
                                    <!-- Modal for details -->
                                    <dialog id="modal-<?= $activity['id'] ?>" 
                                            class="fixed inset-0 z-[10000] flex items-center justify-center p-4 hidden" 
                                            style="background: rgba(17, 24, 39, 0.55); backdrop-filter: blur(4px); display: none; border: none; outline: none;"
                                            onclick="if(event.target === this) this.close()"
                                            onkeydown="if(event.key === 'Escape') this.close()">
                                        <div class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card-lg animate-in fade-in zoom-in duration-200 dark:border-slate-600 dark:bg-slate-900" style="outline: none;">
                                            <!-- Header -->
                                            <div class="flex items-center justify-between border-b border-gray-200 bg-gradient-to-r from-indigo-50 to-purple-50 px-6 py-4 dark:border-slate-600 dark:from-indigo-950/50 dark:to-purple-950/40">
                                                <div class="flex items-center gap-3">
                                                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-100 dark:bg-indigo-900/60">
                                                        <svg class="h-5 w-5 text-indigo-600 dark:text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                                    </div>
                                                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">Activity Details</h3>
                                                </div>
                                                <button type="button" onclick="document.getElementById('modal-<?= $activity['id'] ?>').close()" 
                                                        class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-slate-800 dark:hover:text-slate-200" aria-label="Close">
                                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                                </button>
                                            </div>
                                            
                                            <!-- Body -->
                                            <div class="flex-1 overflow-y-auto px-6 py-6">
                                                <div class="space-y-6">
                                                    <!-- Description -->
                                                    <div class="rounded-xl border border-gray-200 bg-gradient-to-br from-gray-50 to-gray-100 p-4 dark:border-slate-600 dark:from-slate-800/80 dark:to-slate-900/80">
                                                        <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-gray-500 dark:text-slate-400">Description</label>
                                                        <p class="text-sm font-medium leading-relaxed text-gray-900 dark:text-slate-100"><?= e($activity['description']) ?></p>
                                                    </div>
                                                    
                                                    <!-- Metadata -->
                                                    <?php if ($activity['metadata']): ?>
                                                        <div>
                                                            <label class="mb-3 block text-xs font-bold uppercase tracking-widest text-gray-500 dark:text-slate-400">Metadata</label>
                                                            <div class="bg-gray-900 rounded-xl p-4 overflow-x-auto">
                                                                <pre class="text-xs text-gray-100 font-mono leading-relaxed"><?= json_encode($activity['metadata'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?></pre>
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <!-- Additional Info Grid -->
                                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                        <?php if ($activity['ip_address']): ?>
                                                            <div class="rounded-xl border border-indigo-100 bg-indigo-50/80 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/30">
                                                                <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-indigo-700 dark:text-indigo-300">IP Address</label>
                                                                <p class="font-mono text-sm font-semibold text-indigo-950 dark:text-indigo-100"><?= e($activity['ip_address']) ?></p>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($activity['user_agent']): ?>
                                                            <div class="rounded-xl border border-purple-100 bg-purple-50 p-4 dark:border-purple-900/50 dark:bg-purple-950/30">
                                                                <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-purple-600 dark:text-purple-300">User Agent</label>
                                                                <p class="line-clamp-2 text-xs font-medium text-purple-900 dark:text-purple-100"><?= e($activity['user_agent']) ?></p>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <div class="rounded-xl border border-emerald-100 bg-emerald-50 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/30">
                                                            <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-emerald-600 dark:text-emerald-300">Timestamp</label>
                                                            <p class="text-sm font-semibold text-emerald-900 dark:text-emerald-100"><?= formatDateTime($activity['created_at']) ?></p>
                                                            <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-300"><?= timeAgo($activity['created_at']) ?></p>
                                                        </div>
                                                        
                                                        <?php if ($activity['entity_type'] && $activity['entity_id']): ?>
                                                            <div class="rounded-xl border border-amber-100 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-950/30">
                                                                <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-amber-600 dark:text-amber-300">Entity</label>
                                                                <p class="text-sm font-semibold text-amber-900 dark:text-amber-100">
                                                                    <?= e(ucfirst($activity['entity_type'])) ?> #<?= $activity['entity_id'] ?>
                                                                </p>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Footer -->
                                            <div class="flex justify-end border-t border-gray-200 bg-gray-50 px-6 py-4 dark:border-slate-600 dark:bg-slate-800/80">
                                                <button type="button" onclick="document.getElementById('modal-<?= $activity['id'] ?>').close()" 
                                                        class="btn-primary px-6">
                                                    Close
                                                </button>
                                            </div>
                                        </div>
                                    </dialog>
                                <?php else: ?>
                                    <span class="text-xs text-gray-400 dark:text-slate-500">&mdash;</span>
                                <?php endif; ?>
                                
                                <!-- Modal for activities without metadata but with other details -->
                                <?php if (!$activity['metadata'] && ($activity['ip_address'] || $activity['user_agent'])): ?>
                                    <button 
                                        type="button"
                                        onclick="document.getElementById('modal-<?= $activity['id'] ?>').showModal()"
                                        class="cursor-pointer text-xs font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300"
                                    >
                                        View Details
                                    </button>
                                    
                                    <!-- Modal for details -->
                                    <dialog id="modal-<?= $activity['id'] ?>" 
                                            class="fixed inset-0 z-[10000] flex items-center justify-center p-4 hidden" 
                                            style="background: rgba(17, 24, 39, 0.55); backdrop-filter: blur(4px); display: none; border: none; outline: none;"
                                            onclick="if(event.target === this) this.close()"
                                            onkeydown="if(event.key === 'Escape') this.close()">
                                        <div class="flex max-h-[90vh] w-full max-w-2xl flex-col overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-card-lg animate-in fade-in zoom-in duration-200 dark:border-slate-600 dark:bg-slate-900" style="outline: none;">
                                            <!-- Header -->
                                            <div class="flex items-center justify-between border-b border-gray-200 bg-gradient-to-r from-indigo-50 to-purple-50 px-6 py-4 dark:border-slate-600 dark:from-indigo-950/50 dark:to-purple-950/40">
                                                <div class="flex items-center gap-3">
                                                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-100 dark:bg-indigo-900/60">
                                                        <svg class="h-5 w-5 text-indigo-600 dark:text-indigo-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                                    </div>
                                                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">Activity Details</h3>
                                                </div>
                                                <button type="button" onclick="document.getElementById('modal-<?= $activity['id'] ?>').close()" 
                                                        class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-slate-800 dark:hover:text-slate-200" aria-label="Close">
                                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                                </button>
                                            </div>
                                            
                                            <!-- Body -->
                                            <div class="flex-1 overflow-y-auto px-6 py-6">
                                                <div class="space-y-6">
                                                    <!-- Description -->
                                                    <div class="rounded-xl border border-gray-200 bg-gradient-to-br from-gray-50 to-gray-100 p-4 dark:border-slate-600 dark:from-slate-800/80 dark:to-slate-900/80">
                                                        <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-gray-500 dark:text-slate-400">Description</label>
                                                        <p class="text-sm font-medium leading-relaxed text-gray-900 dark:text-slate-100"><?= e($activity['description']) ?></p>
                                                    </div>
                                                    
                                                    <!-- Additional Info Grid -->
                                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                                        <?php if ($activity['ip_address']): ?>
                                                            <div class="rounded-xl border border-indigo-100 bg-indigo-50/80 p-4 dark:border-indigo-900/50 dark:bg-indigo-950/30">
                                                                <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-indigo-700 dark:text-indigo-300">IP Address</label>
                                                                <p class="font-mono text-sm font-semibold text-indigo-950 dark:text-indigo-100"><?= e($activity['ip_address']) ?></p>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($activity['user_agent']): ?>
                                                            <div class="rounded-xl border border-purple-100 bg-purple-50 p-4 dark:border-purple-900/50 dark:bg-purple-950/30">
                                                                <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-purple-600 dark:text-purple-300">User Agent</label>
                                                                <p class="line-clamp-2 text-xs font-medium text-purple-900 dark:text-purple-100"><?= e($activity['user_agent']) ?></p>
                                                            </div>
                                                        <?php endif; ?>
                                                        
                                                        <div class="rounded-xl border border-emerald-100 bg-emerald-50 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/30">
                                                            <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-emerald-600 dark:text-emerald-300">Timestamp</label>
                                                            <p class="text-sm font-semibold text-emerald-900 dark:text-emerald-100"><?= formatDateTime($activity['created_at']) ?></p>
                                                            <p class="mt-1 text-xs text-emerald-700 dark:text-emerald-300"><?= timeAgo($activity['created_at']) ?></p>
                                                        </div>
                                                        
                                                        <?php if ($activity['entity_type'] && $activity['entity_id']): ?>
                                                            <div class="rounded-xl border border-amber-100 bg-amber-50 p-4 dark:border-amber-900/50 dark:bg-amber-950/30">
                                                                <label class="mb-2 block text-xs font-bold uppercase tracking-widest text-amber-600 dark:text-amber-300">Entity</label>
                                                                <p class="text-sm font-semibold text-amber-900 dark:text-amber-100">
                                                                    <?= e(ucfirst($activity['entity_type'])) ?> #<?= $activity['entity_id'] ?>
                                                                </p>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                            
                                            <!-- Footer -->
                                            <div class="flex justify-end border-t border-gray-200 bg-gray-50 px-6 py-4 dark:border-slate-600 dark:bg-slate-800/80">
                                                <button type="button" onclick="document.getElementById('modal-<?= $activity['id'] ?>').close()" 
                                                        class="btn-primary px-6">
                                                    Close
                                                </button>
                                            </div>
                                        </div>
                                    </dialog>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalCount > $perPage): ?>
        <div class="flex items-center justify-between border-t border-gray-200 px-6 py-4 dark:border-slate-700">
            <div class="text-sm text-gray-600 dark:text-slate-400">
                Showing <?= number_format(($pageNum - 1) * $perPage + 1) ?> to <?= number_format(min($pageNum * $perPage, $totalCount)) ?> of <?= number_format($totalCount) ?> activities
            </div>
            <div class="flex gap-2">
                <?php if ($pageNum > 1): ?>
                    <a href="?page=activity-log&p=<?= $pageNum - 1 ?>&action_type=<?= e($actionType) ?>&entity_type=<?= e($entityType) ?>&search=<?= e($search) ?>&date_from=<?= e($dateFrom) ?>&date_to=<?= e($dateTo) ?>" 
                       class="btn-secondary">
                        Previous
                    </a>
                <?php endif; ?>
                <?php if ($pageNum * $perPage < $totalCount): ?>
                    <a href="?page=activity-log&p=<?= $pageNum + 1 ?>&action_type=<?= e($actionType) ?>&entity_type=<?= e($entityType) ?>&search=<?= e($search) ?>&date_from=<?= e($dateFrom) ?>&date_to=<?= e($dateTo) ?>" 
                       class="btn-primary">
                        Next
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<style>
/* Modal animations */
@keyframes fade-in {
    from { opacity: 0; }
    to { opacity: 1; }
}

@keyframes zoom-in {
    from { transform: scale(0.95); }
    to { transform: scale(1); }
}

.animate-in {
    animation: fade-in 0.2s ease-out, zoom-in 0.2s ease-out;
}

dialog::backdrop {
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
}

dialog[open] {
    display: flex !important;
    animation: fade-in 0.2s ease-out;
}

dialog:not([open]) {
    display: none !important;
    pointer-events: none;
}

dialog[open] {
    pointer-events: auto;
}

dialog {
    border: none !important;
    outline: none !important;
}

dialog::backdrop {
    background: rgba(0, 0, 0, 0.5);
    backdrop-filter: blur(4px);
}

.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>

