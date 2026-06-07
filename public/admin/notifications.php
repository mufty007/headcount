<?php

/**
 * Admin Notifications Page
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Calculate base path if not set (from index.php)
if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}

// Load helpers
require_once __DIR__ . '/../../src/helpers.php';

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

// Get filter parameters
$filter = get('filter', 'all'); // all, unread, read
$pageNum = max(1, (int)get('p', 1));
$perPage = 20;

// Build query
$where = "organization_id = :org_id AND (user_id IS NULL OR user_id = :user_id)";
$params = ['org_id' => $organizationId, 'user_id' => $userId];

if ($filter === 'unread') {
    $where .= " AND is_read = 0";
} elseif ($filter === 'read') {
    $where .= " AND is_read = 1";
}

// Get total count
$totalCount = $db->queryOne(
    "SELECT COUNT(*) as count FROM notifications WHERE $where",
    $params
)['count'] ?? 0;

// Get notifications with pagination
$offset = ($pageNum - 1) * $perPage;
$sql = "SELECT * FROM notifications 
        WHERE $where 
        ORDER BY created_at DESC 
        LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
$notifications = $db->query($sql, $params);

// Get unread count
$unreadCount = $db->queryOne(
    "SELECT COUNT(*) as count FROM notifications 
     WHERE organization_id = :org_id AND (user_id IS NULL OR user_id = :user_id) AND is_read = 0",
    ['org_id' => $organizationId, 'user_id' => $userId]
)['count'] ?? 0;

$totalPages = ceil($totalCount / $perPage);

$pageTitle = 'Notifications';
$currentPage = 'notifications';

// Calculate base URL for API calls
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
$basePath = preg_replace('#/admin/.*$#', '', $requestPath);
$basePath = rtrim($basePath, '/');
$apiBase = $basePath . '/public/api';

require_once __DIR__ . '/includes/header.php';
?>

<div class="animate-fade-in">
    <?php
    $pageHeaderTitle = 'Notifications';
    $pageHeaderSubtitle = 'Manage and view all your notifications';
    $pageHeaderActions = ($unreadCount > 0) ? '<button onclick="markAllRead()" class="btn-primary whitespace-nowrap flex-shrink-0"><span>Mark All as Read</span></button>' : '';
    require __DIR__ . '/components/page-header.php';
    ?>

    <!-- Filter Tabs -->
    <div class="mb-6 overflow-hidden rounded-2xl border border-gray-200 bg-white p-1.5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex flex-wrap gap-1">
            <a href="?page=notifications&filter=all"
               class="rounded-lg px-4 py-2 text-theme-sm font-medium transition-colors <?= $filter === 'all' ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/[0.05]' ?>">
                All (<?= $totalCount ?>)
            </a>
            <a href="?page=notifications&filter=unread"
               class="rounded-lg px-4 py-2 text-theme-sm font-medium transition-colors <?= $filter === 'unread' ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/[0.05]' ?>">
                Unread (<?= $unreadCount ?>)
            </a>
            <a href="?page=notifications&filter=read"
               class="rounded-lg px-4 py-2 text-theme-sm font-medium transition-colors <?= $filter === 'read' ? 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-400 dark:hover:bg-white/[0.05]' ?>">
                Read (<?= $totalCount - $unreadCount ?>)
            </a>
        </div>
    </div>

    <!-- Notifications List -->
    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="divide-y divide-gray-100 dark:divide-gray-800">
            <?php if (empty($notifications)): ?>
                <div class="p-12 text-center">
                    <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                    </svg>
                    <h3 class="mt-4 text-theme-sm font-semibold text-gray-900 dark:text-white/90">No notifications</h3>
                    <p class="mt-2 text-theme-sm text-gray-500 dark:text-gray-400">
                        <?= $filter === 'unread' ? 'You have no unread notifications.' : 'You have no notifications yet.' ?>
                    </p>
                </div>
            <?php else: ?>
                <?php foreach ($notifications as $notification): ?>
                    <div
                        class="p-5 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.02] sm:p-6 <?= !$notification['is_read'] ? 'bg-brand-50/30 dark:bg-brand-500/5' : '' ?>"
                        id="notification-<?= $notification['id'] ?>"
                    >
                        <div class="flex items-start gap-4">
                            <!-- Icon -->
                            <div class="flex-shrink-0">
                                <?php
                                $iconClasses = [
                                    'event_reminder' => 'bg-brand-100 text-brand-600 dark:bg-brand-900/50 dark:text-brand-300',
                                    'new_rsvp' => 'bg-green-100 text-green-600 dark:bg-emerald-900/40 dark:text-emerald-300',
                                    'event_cancelled' => 'bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-300',
                                    'member_added' => 'bg-purple-100 text-purple-600 dark:bg-purple-900/40 dark:text-purple-300',
                                    'payment_received' => 'bg-yellow-100 text-yellow-600 dark:bg-amber-900/40 dark:text-amber-300',
                                    'system' => 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300',
                                    'info' => 'bg-brand-100 text-brand-600 dark:bg-brand-900/50 dark:text-brand-300'
                                ];
                                $iconClass = $iconClasses[$notification['type']] ?? $iconClasses['info'];
                                ?>
                                <div class="w-10 h-10 rounded-full flex items-center justify-center <?= $iconClass ?>">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                    </svg>
                                </div>
                            </div>

                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-4">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <h3 class="text-base font-semibold text-gray-900">
                                                <?= htmlspecialchars($notification['title']) ?>
                                            </h3>
                                            <?php if (!$notification['is_read']): ?>
                                                <?php $chipLabel = 'New'; $chipVariant = 'indigo'; require __DIR__ . '/components/chip.php'; ?>
                                            <?php endif; ?>
                                        </div>
                                        <p class="mt-1 text-sm text-gray-600">
                                            <?= htmlspecialchars($notification['message']) ?>
                                        </p>
                                        <div class="mt-2 flex items-center gap-4 text-xs text-gray-500">
                                            <span>
                                                <?= Utilities::formatDate($notification['created_at'], 'M j, Y') ?>
                                                at <?= Utilities::formatTime($notification['created_at']) ?>
                                            </span>
                                            <?php if ($notification['read_at']): ?>
                                                <span>Read <?= Utilities::formatDate($notification['read_at'], 'M j, Y') ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Actions -->
                                    <div class="flex items-center gap-2">
                                        <?php if (!$notification['is_read']): ?>
                                            <button 
                                                onclick="markAsRead(<?= $notification['id'] ?>)"
                                                class="rounded-md px-3 py-1.5 text-xs font-medium text-brand-600 transition-colors hover:bg-brand-50 hover:text-brand-700 dark:text-brand-400 dark:hover:bg-brand-950/50 dark:hover:text-brand-300"
                                            >
                                                Mark as read
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($notification['link']): ?>
                                            <a 
                                                href="<?= htmlspecialchars($notification['link']) ?>"
                                                class="rounded-md px-3 py-1.5 text-xs font-medium text-gray-700 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white"
                                            >
                                                View
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1):
        $paginationBaseUrl = '?page=notifications&filter=' . urlencode($filter);
        $paginationCurrentPage = $pageNum;
        $paginationTotalPages = $totalPages;
        $paginationTotal = $totalCount;
        $paginationPerPage = $perPage;
        require __DIR__ . '/components/pagination.php';
    endif; ?>

</div>

<script>
const apiBase = '<?= htmlspecialchars($apiBase, ENT_QUOTES) ?>';

async function markAsRead(id) {
    try {
        const response = await fetch(`${apiBase}/notifications.php?action=mark_read`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: id })
        });
        
        const data = await response.json();
        if (data.success) {
            // Update UI
            const notificationEl = document.getElementById(`notification-${id}`);
            if (notificationEl) {
                notificationEl.classList.remove('bg-brand-50/30', 'dark:bg-brand-950/35');
                const badge = notificationEl.querySelector('.bg-brand-100');
                if (badge) badge.remove();
                const button = notificationEl.querySelector('button');
                if (button) button.remove();
            }
            
            // Reload page to update counts
            setTimeout(() => {
                window.location.reload();
            }, 300);
        } else {
            alert('Failed to mark notification as read');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred');
    }
}

async function markAllRead() {
    if (!confirm('Mark all notifications as read?')) return;
    
    try {
        const response = await fetch(`${apiBase}/notifications.php?action=mark_all_read`, {
            method: 'POST'
        });
        
        const data = await response.json();
        if (data.success) {
            window.location.reload();
        } else {
            alert('Failed to mark all as read');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('An error occurred');
    }
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

