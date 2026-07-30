<?php
if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

// Calculate base path if not set (from index.php)
if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $adminBase . '/?page=login');
    exit;
}

// Load helpers

use Headcount\Helpers\Database;
use Headcount\Helpers\Utilities;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Middleware\CsrfMiddleware;

AuthMiddleware::requireCan('members.manage');
$organizationId = AuthMiddleware::getOrganizationId();
// Ensure we have a valid org for queries (session can miss organization_id on some setups)
if ($organizationId === null || $organizationId === '' || (int)$organizationId < 1) {
    $organizationId = 1;
    if (function_exists('error_log')) {
        error_log('Members page: organization_id missing in session, using fallback 1');
    }
} else {
    $organizationId = (int)$organizationId;
}
$db = Database::getInstance();

// Get the current user for the header
$userId = AuthMiddleware::getUserId();
$userData = $db->queryOne("SELECT first_name, last_name, email FROM users WHERE id = :id", ['id' => $userId]);
$user = $userData ? [
    'name' => trim($userData['first_name'] . ' ' . $userData['last_name']),
    'email' => $userData['email']
] : [
    'name' => 'Administrator',
    'email' => 'admin@headcount.local'
];

// Get filter parameters
$gender = get('gender', 'all');
$status = get('status', 'active');
$search = get('search', '');
$tagFilter = get('tag', 'all');
$groupFilter = get('group', 'all');

/** Append gender WHERE clause; "unassigned" matches null/empty/unspecified. */
$appendMemberGenderFilter = function (string &$sql, array &$params) use ($gender): void {
    if ($gender === 'all') {
        return;
    }
    if ($gender === 'unassigned') {
        $sql .= " AND (u.gender IS NULL OR TRIM(COALESCE(u.gender, '')) = '' OR LOWER(TRIM(u.gender)) IN ('unspecified', 'unknown', 'none'))";
        return;
    }
    $sql .= " AND u.gender = :gender";
    $params['gender'] = $gender;
};

// Reusable search clause: name/email/phone (all phone formats + country-code variants)
$memberSearchSql = '';
$memberSearchParams = [];
if ($search !== '') {
    $memberSearchSql = " AND (u.first_name LIKE :search1 OR u.last_name LIKE :search1 OR u.email LIKE :search1 OR u.phone LIKE :search1 OR TRIM(CONCAT(COALESCE(u.first_name,''), ' ', COALESCE(u.last_name,''))) LIKE :search1";
    $memberSearchParams['search1'] = '%' . $search . '%';
    $phoneClause = headcount_phone_search_clause($search, 'u.phone', 'searchPhone');
    if ($phoneClause !== null) {
        $memberSearchSql .= ' OR ' . $phoneClause['sql'];
        $memberSearchParams = array_merge($memberSearchParams, $phoneClause['params']);
    }
    $memberSearchSql .= ")";
}

// Pagination (do not use $page — that name is used by admin index.php for the route name)
$membersListPage = max(1, (int)get('p', 1));
$perPage = max(10, min(100, (int)get('per_page', 25))); // Default 25, min 10, max 100

// Check if tags and groups tables exist
$tagsTableExists = false;
$groupsTableExists = false;
try {
    $result = $db->query("SHOW TABLES LIKE 'tags'");
    $tagsTableExists = !empty($result);
} catch (\Exception $e) {
    $tagsTableExists = false;
}

try {
    $result = $db->query("SHOW TABLES LIKE 'member_groups'");
    $groupsTableExists = !empty($result);
} catch (\Exception $e) {
    $groupsTableExists = false;
}

// Build query with attendance stats, tags, and groups (if tables exist)
if ($tagsTableExists && $groupsTableExists) {
    $sql = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.phone, u.gender, u.status, u.organization_id, u.role, u.created_at,
            (SELECT COUNT(*) FROM attendance WHERE user_id = u.id AND checked_in_at IS NOT NULL) as total_events,
            (SELECT MAX(checked_in_at) FROM attendance WHERE user_id = u.id) as last_attendance,
            GROUP_CONCAT(DISTINCT CONCAT(t.id, ':::', t.name, ':::', COALESCE(t.color, '#6366F1')) SEPARATOR '|||') as tags_data,
            GROUP_CONCAT(DISTINCT CONCAT(mg.id, ':::', mg.name, ':::', COALESCE(mg.color, '#10B981')) SEPARATOR '|||') as groups_data,
            GROUP_CONCAT(DISTINCT t.name) as tags,
            GROUP_CONCAT(DISTINCT mg.name) as groups
            FROM users u 
            LEFT JOIN member_tags mt ON u.id = mt.user_id
            LEFT JOIN tags t ON mt.tag_id = t.id
            LEFT JOIN group_members gm ON u.id = gm.user_id
            LEFT JOIN member_groups mg ON gm.group_id = mg.id
            WHERE u.organization_id = :org_id AND u.role = 'member'";
} else {
    // Fallback query without tags and groups
    $sql = "SELECT DISTINCT u.id, u.first_name, u.last_name, u.email, u.phone, u.gender, u.status, u.organization_id, u.role, u.created_at,
            (SELECT COUNT(*) FROM attendance WHERE user_id = u.id AND checked_in_at IS NOT NULL) as total_events,
            (SELECT MAX(checked_in_at) FROM attendance WHERE user_id = u.id) as last_attendance,
            NULL as tags,
            NULL as groups,
            NULL as tags_data,
            NULL as groups_data
            FROM users u 
            WHERE u.organization_id = :org_id AND u.role = 'member'";
}

$params = ['org_id' => $organizationId];

if ($status !== 'all') {
    $sql .= " AND u.status = :status";
    $params['status'] = $status;
} else {
    $sql .= " AND u.status != 'deleted'";
}

$appendMemberGenderFilter($sql, $params);

if ($memberSearchSql !== '') {
    $sql .= $memberSearchSql;
    $params = array_merge($params, $memberSearchParams);
}

$sql .= " GROUP BY u.id";

// Filter by tag / group (after GROUP BY) - only if tables exist
$havingConditions = [];
if ($tagFilter !== 'all' && $tagsTableExists) {
    if ($tagFilter === '__none__') {
        $havingConditions[] = "(tags IS NULL OR tags = '')";
    } else {
        $havingConditions[] = "FIND_IN_SET(:tagFilter, tags) > 0";
        $params['tagFilter'] = $tagFilter;
    }
}
if ($groupFilter !== 'all' && $groupsTableExists) {
    if ($groupFilter === '__none__') {
        $havingConditions[] = "(groups IS NULL OR groups = '')";
    } else {
        $havingConditions[] = "FIND_IN_SET(:groupFilter, groups) > 0";
        $params['groupFilter'] = $groupFilter;
    }
}
if (!empty($havingConditions)) {
    $sql .= " HAVING " . implode(' AND ', $havingConditions);
}

// Build count query - use the same base query structure but count distinct IDs
// We'll execute the main query first to get results, then count them
// This is more reliable than trying to replicate complex HAVING clauses

// First, build a simplified count query
$countSql = "SELECT COUNT(DISTINCT u.id) as total
    FROM users u";
    
$countParams = ['org_id' => $organizationId];

// Add joins only if we need them for filtering
$needsJoins = ($tagFilter !== 'all' && $tagsTableExists) || ($groupFilter !== 'all' && $groupsTableExists);
if ($needsJoins && $tagsTableExists && $groupsTableExists) {
    $countSql .= " 
        LEFT JOIN member_tags mt ON u.id = mt.user_id
        LEFT JOIN tags t ON mt.tag_id = t.id
        LEFT JOIN group_members gm ON u.id = gm.user_id
        LEFT JOIN member_groups mg ON gm.group_id = mg.id";
}

$countSql .= " WHERE u.organization_id = :org_id AND u.role = 'member'";

if ($status !== 'all') {
    $countSql .= " AND u.status = :status";
    $countParams['status'] = $status;
} else {
    $countSql .= " AND u.status != 'deleted'";
}

$appendMemberGenderFilter($countSql, $countParams);

if ($memberSearchSql !== '') {
    $countSql .= $memberSearchSql;
    $countParams = array_merge($countParams, $memberSearchParams);
}

// For tag/group filters, we need to use a subquery with HAVING
if (($tagFilter !== 'all' && $tagsTableExists) || ($groupFilter !== 'all' && $groupsTableExists)) {
    // Rebuild countParams to ensure all needed params are included
    $countParams = ['org_id' => $organizationId];
    
    $countSql = "SELECT COUNT(*) as total FROM (
        SELECT DISTINCT u.id";
    
    // Add GROUP_CONCAT for tags if table exists
    if ($tagsTableExists) {
        $countSql .= ",
            GROUP_CONCAT(DISTINCT t.name) as tags";
    }
    
    // Add GROUP_CONCAT for groups if table exists
    if ($groupsTableExists) {
        $countSql .= ",
            GROUP_CONCAT(DISTINCT mg.name) as groups";
    }
    
    $countSql .= "
        FROM users u";
    
    if ($tagsTableExists && $groupsTableExists) {
        $countSql .= " 
            LEFT JOIN member_tags mt ON u.id = mt.user_id
            LEFT JOIN tags t ON mt.tag_id = t.id
            LEFT JOIN group_members gm ON u.id = gm.user_id
            LEFT JOIN member_groups mg ON gm.group_id = mg.id";
    } elseif ($tagsTableExists) {
        $countSql .= " 
            LEFT JOIN member_tags mt ON u.id = mt.user_id
            LEFT JOIN tags t ON mt.tag_id = t.id";
    } elseif ($groupsTableExists) {
        $countSql .= " 
            LEFT JOIN group_members gm ON u.id = gm.user_id
            LEFT JOIN member_groups mg ON gm.group_id = mg.id";
    }
    
    $countSql .= " WHERE u.organization_id = :org_id AND u.role = 'member'";
    
    if ($status !== 'all') {
        $countSql .= " AND u.status = :status";
        $countParams['status'] = $status;
    } else {
        $countSql .= " AND u.status != 'deleted'";
    }
    
    $appendMemberGenderFilter($countSql, $countParams);
    
    if ($memberSearchSql !== '') {
        $countSql .= $memberSearchSql;
        $countParams = array_merge($countParams, $memberSearchParams);
    }
    
    $countSql .= " GROUP BY u.id";
    
    $havingConditions = [];
    if ($tagFilter !== 'all' && $tagsTableExists) {
        if ($tagFilter === '__none__') {
            $havingConditions[] = "(tags IS NULL OR tags = '')";
        } else {
            $havingConditions[] = "FIND_IN_SET(:tagFilter, tags) > 0";
            $countParams['tagFilter'] = $tagFilter;
        }
    }
    if ($groupFilter !== 'all' && $groupsTableExists) {
        if ($groupFilter === '__none__') {
            $havingConditions[] = "(groups IS NULL OR groups = '')";
        } else {
            $havingConditions[] = "FIND_IN_SET(:groupFilter, groups) > 0";
            $countParams['groupFilter'] = $groupFilter;
        }
    }
    
    if (!empty($havingConditions)) {
        $countSql .= " HAVING " . implode(" AND ", $havingConditions);
    }
    
    $countSql .= ") as filtered";
}

// Get total count
try {
    $totalCountResult = $db->queryOne($countSql, $countParams);
    $totalCount = $totalCountResult ? (int)$totalCountResult['total'] : 0;
} catch (\Exception $e) {
    // Fallback: use a simpler count query
    error_log("Count query failed: " . $e->getMessage());
    
    // Reinitialize countParams to ensure no leftover parameters from complex query
    $countSql = "SELECT COUNT(DISTINCT u.id) as total FROM users u WHERE u.organization_id = :org_id AND u.role = 'member'";
    $countParams = ['org_id' => $organizationId];
    
    if ($status !== 'all') {
        $countSql .= " AND u.status = :status";
        $countParams['status'] = $status;
    } else {
        $countSql .= " AND u.status != 'deleted'";
    }
    
    $appendMemberGenderFilter($countSql, $countParams);
    
    if ($memberSearchSql !== '') {
        $countSql .= $memberSearchSql;
        $countParams = array_merge($countParams, $memberSearchParams);
    }
    
    try {
        $totalCountResult = $db->queryOne($countSql, $countParams);
        $totalCount = $totalCountResult ? (int)$totalCountResult['total'] : 0;
    } catch (\Exception $e2) {
        // Final fallback: just count all members
        error_log("Fallback count query also failed: " . $e2->getMessage());
        $totalCount = 0;
    }
}

$sql .= " ORDER BY u.first_name ASC, u.last_name ASC";

// Calculate pagination
$pagination = Utilities::paginate($totalCount, $membersListPage, $perPage);

// Add LIMIT and OFFSET to main query (use integers, not named parameters)
$limit = (int)$pagination['per_page'];
$offset = (int)$pagination['offset'];
$sql .= " LIMIT $limit OFFSET $offset";

try {
    $members = $db->query($sql, $params);
} catch (\Exception $e) {
    // If query fails, try simpler version without tags/groups
    error_log("Members query failed: " . $e->getMessage());
    
    // Rebuild SQL and params from scratch to ensure they match exactly
    $sql = "SELECT DISTINCT u.*, 
            (SELECT COUNT(*) FROM attendance WHERE user_id = u.id AND checked_in_at IS NOT NULL) as total_events,
            (SELECT MAX(checked_in_at) FROM attendance WHERE user_id = u.id) as last_attendance,
            NULL as tags,
            NULL as groups,
            NULL as tags_data,
            NULL as groups_data
            FROM users u 
            WHERE u.organization_id = :org_id AND u.role = 'member'";
    
    // Rebuild params array from scratch
    $params = ['org_id' => $organizationId];
    
    if ($status !== 'all') {
        $sql .= " AND u.status = :status";
        $params['status'] = $status;
    } else {
        $sql .= " AND u.status != 'deleted'";
    }
    
    $appendMemberGenderFilter($sql, $params);
    
    if ($memberSearchSql !== '') {
        $sql .= $memberSearchSql;
        $params = array_merge($params, $memberSearchParams);
    }
    
    $sql .= " ORDER BY u.first_name ASC, u.last_name ASC";
    
    // Get total count for fallback query
    $countSql = "SELECT COUNT(DISTINCT u.id) as total FROM users u WHERE u.organization_id = :org_id AND u.role = 'member'";
    $countParams = ['org_id' => $organizationId];
    
    if ($status !== 'all') {
        $countSql .= " AND u.status = :status";
        $countParams['status'] = $status;
    } else {
        $countSql .= " AND u.status != 'deleted'";
    }
    
    $appendMemberGenderFilter($countSql, $countParams);
    
    if ($memberSearchSql !== '') {
        $countSql .= $memberSearchSql;
        $countParams = array_merge($countParams, $memberSearchParams);
    }
    
    try {
        $totalCountResult = $db->queryOne($countSql, $countParams);
        $totalCount = $totalCountResult ? (int)$totalCountResult['total'] : 0;
    } catch (\Exception $e2) {
        error_log("Fallback count query also failed: " . $e2->getMessage());
        $totalCount = 0;
    }
    
    // Calculate pagination for fallback
    $pagination = Utilities::paginate($totalCount, $membersListPage, $perPage);
    
    // Add LIMIT and OFFSET (use integers, not named parameters)
    $limit = (int)$pagination['per_page'];
    $offset = (int)$pagination['offset'];
    $sql .= " LIMIT $limit OFFSET $offset";
    
    try {
        $members = $db->query($sql, $params);
    } catch (\Exception $e3) {
        error_log("Fallback members query also failed: " . $e3->getMessage());
        $members = [];
    }
}

// Get all tags and groups for filters
try {
    $allTags = $db->query("SELECT * FROM tags WHERE organization_id = :org_id ORDER BY name", ['org_id' => $organizationId]);
} catch (\Exception $e) {
    $allTags = [];
}

try {
    $allGroups = $db->query("SELECT * FROM member_groups WHERE organization_id = :org_id ORDER BY name", ['org_id' => $organizationId]);
} catch (\Exception $e) {
    $allGroups = [];
}

// Get statistics
$totalMembersResult = $db->queryOne("SELECT COUNT(*) as count FROM users WHERE role = 'member' AND status = 'active' AND organization_id = :org_id", ['org_id' => $organizationId]);
$totalMembers = $totalMembersResult ? (int)$totalMembersResult['count'] : 0;

$withEmailResult = $db->queryOne("SELECT COUNT(*) as count FROM users WHERE role = 'member' AND status = 'active' AND email IS NOT NULL AND email != '' AND organization_id = :org_id", ['org_id' => $organizationId]);
$withEmail = $withEmailResult ? (int)$withEmailResult['count'] : 0;

// Get email templates for bulk email modal
try {
    $templates = $db->query("SELECT * FROM email_templates WHERE organization_id = :org_id ORDER BY template_type, subject", ['org_id' => $organizationId]);
} catch (\Exception $e) {
    $templates = [];
}

// Ensure arrays for template/JSON (avoid 500 from foreach or json_encode on null)
$allTags = isset($allTags) && is_array($allTags) ? $allTags : [];
$allGroups = isset($allGroups) && is_array($allGroups) ? $allGroups : [];

// Generate CSRF token
$csrfToken = CsrfMiddleware::getToken();

// Calculate asset paths
$cssBase = $basePath . '/public/css/';
$jsBase = $basePath . '/public/js/';
// Construct API base URL
$apiBase = ($basePath ? rtrim($basePath, '/') : '') . '/api';

// modal.css is loaded globally in header.php
$additionalCSS = [];

$pageTitle = 'Members';
$currentPage = 'members';
require __DIR__ . '/includes/header.php';
?>

<div x-data="membersApp()" x-init="init(); window.membersAppInstance = $data">
    <!-- Bulk Email Modal (first child so Alpine scope is definitely membersApp) -->
    <div x-show="showBulkEmailModal" 
         class="fixed inset-0 z-[100] overflow-y-auto" 
         x-cloak>
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div x-show="showBulkEmailModal" 
                 x-transition:enter="ease-out duration-300" 
                 x-transition:enter-start="opacity-0" 
                 x-transition:enter-end="opacity-100" 
                 x-transition:leave="ease-in duration-200" 
                 x-transition:leave-start="opacity-100" 
                 x-transition:leave-end="opacity-0" 
                 @click="showBulkEmailModal = false"
                 class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px] transition-opacity dark:bg-black/60"></div>

            <div x-show="showBulkEmailModal" 
                 x-transition:enter="ease-out duration-300" 
                 x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" 
                 x-transition:enter-end="opacity-100 translate-y-0 scale-100" 
                 x-transition:leave="ease-in duration-200" 
                 x-transition:leave-start="opacity-100 translate-y-0 scale-100" 
                 x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" 
                 class="inline-block transform overflow-hidden rounded-2xl border border-gray-200 bg-white text-left align-bottom shadow-card-lg transition-all dark:border-gray-600 dark:bg-gray-800 sm:my-8 sm:max-w-4xl sm:w-full sm:align-middle">
                
                <div class="bg-white px-8 pt-6 pb-4 dark:bg-gray-800 sm:p-10 sm:pb-4">
                    <div class="mb-8 flex items-center justify-between border-b border-gray-100 pb-4 dark:border-gray-700">
                        <div>
                            <h3 class="text-2xl font-extrabold tracking-tight text-gray-900 dark:text-white">Send Bulk Email</h3>
                            <p class="mt-1 text-gray-500 dark:text-gray-400" x-text="'Sending to ' + selectedMembers.length + ' selected members'"></p>
                        </div>
                        <button type="button" @click="showBulkEmailModal = false" class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-gray-700 dark:hover:text-white dark:bg-gray-800 dark:text-gray-200" aria-label="Close">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <div class="space-y-6">
                            <div>
                                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Select Template</label>
                                <select x-model="bulkEmailForm.template_id"
                                        @change="loadEmailTemplate()"
                                        class="ta-select w-full">
                                    <option value="">-- Custom Email / No Template --</option>
                                    <?php foreach ($templates as $template): ?>
                                        <option value="<?= $template['id'] ?>">
                                            <?= e(ucfirst(str_replace('_', ' ', $template['template_type']))) ?>: <?= e($template['subject']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Email Subject</label>
                                <input type="text" x-model="bulkEmailForm.subject"
                                       @input="generatePreview()"
                                       placeholder="Enter email subject"
                                       class="ta-input w-full">
                            </div>

                            <div>
                                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Message</label>
                                <div id="bulk-email-body-wrap" class="rounded-xl border border-gray-200 overflow-hidden bg-white dark:bg-gray-800 dark:border-gray-700">
                                    <textarea id="bulk-email-composer-body" class="wysiwyg-editor w-full text-sm" rows="6"
                                              x-model="bulkEmailForm.body"
                                              @input="generatePreview()"
                                              placeholder="Enter your message..."></textarea>
                                </div>
                                <p class="text-[10px] text-gray-400 mt-2 italic">You can use {first_name}, {last_name}, {email}, etc. as merge tags.</p>
                            </div>
                        </div>

                        <div class="flex flex-col rounded-2xl border border-gray-100 bg-gray-50 p-6 dark:border-gray-600 dark:bg-gray-900/50">
                            <h4 class="mb-4 flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-gray-400 dark:text-gray-500">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                Live Preview
                            </h4>
                            <div class="flex flex-1 flex-col overflow-hidden rounded-xl border border-gray-100 bg-white shadow-sm dark:border-gray-600 dark:bg-gray-900">
                                <div class="px-4 py-3 border-b border-gray-100 bg-gray-50/50 dark:bg-gray-800 dark:border-gray-800">
                                    <div class="text-[10px] text-gray-400 uppercase font-bold tracking-widest mb-1">Subject</div>
                                    <div class="text-sm font-bold text-gray-900 truncate dark:text-white" x-text="previewSubject || '(No Subject)'"></div>
                                </div>
                                <div class="px-4 py-3 border-b border-gray-100 dark:border-gray-800">
                                    <div class="text-[10px] text-gray-400 uppercase font-bold tracking-widest mb-1">From</div>
                                    <div class="text-sm text-gray-600 dark:text-gray-300">IMCA via SMTP2GO</div>
                                </div>
                                <div class="custom-scrollbar preview-body-content flex-1 overflow-y-auto bg-white p-6 dark:bg-gray-900" x-html="previewBody || '<p class=\'text-gray-400 italic\'>Start typing to see preview...</p>'"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-gray-50 px-8 py-6 sm:px-10 flex flex-wrap items-center justify-between gap-4 dark:bg-gray-800">
                    <div class="flex items-center gap-4">
                        <span class="text-xs text-gray-500 font-medium dark:text-gray-400" x-show="sendingBulk">
                            <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-brand-600 inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            Sending emails... Please don't close this window.
                        </span>
                    </div>
                    <div class="flex gap-3">
                        <button @click="showBulkEmailModal = false" class="btn-secondary">Cancel</button>
                        <button @click="sendBulkEmail()" 
                                :disabled="sendingBulk || (!bulkEmailForm.subject || !bulkEmailForm.body)"
                                class="btn-primary disabled:opacity-50 min-w-[140px]">
                            <span x-show="!sendingBulk" x-text="'Send to ' + selectedMembers.length + ' People'"></span>
                            <span x-show="sendingBulk">Sending...</span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <style>
    .preview-body-content { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    .preview-body-content h1, .preview-body-content h2, .preview-body-content h3 { margin-top: 1.5em; margin-bottom: 0.5em; font-weight: bold; color: #111827; }
    .preview-body-content h2 { font-size: 1.5rem; border-bottom: 2px solid #F3F4F6; padding-bottom: 0.25rem; }
    .preview-body-content p { margin-bottom: 1em; line-height: 1.6; color: #374151; }
    .preview-body-content a { color: #4F46E5; text-decoration: underline; }
    .preview-body-content ul, .preview-body-content ol { margin-bottom: 1em; padding-left: 1.5em; }
    .preview-body-content li { margin-bottom: 0.5em; }
    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #F9FAFB; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 10px; }
    </style>

    <?php
    $pageHeaderTitle = 'Members';
    $pageHeaderSubtitle = $totalMembers . ' active members ' . "\u{2022}" . ' ' . $withEmail . ' with email';
    ob_start();
    ?>
    <div class="flex flex-wrap items-center gap-3">
        <button @click="openTagsManager()" class="btn-secondary inline-flex items-center gap-2 whitespace-nowrap flex-shrink-0">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path></svg>
            <span>Tags</span>
        </button>
        <button @click="openGroupsManager()" class="btn-secondary inline-flex items-center gap-2 whitespace-nowrap flex-shrink-0">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
            <span>Groups</span>
        </button>
        <button @click="showImportModal = true" class="btn-secondary inline-flex items-center gap-2 whitespace-nowrap flex-shrink-0">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
            <span>Import</span>
        </button>
        <span class="hidden sm:block w-px h-8 bg-gray-200 flex-shrink-0" aria-hidden="true"></span>
        <a href="<?= e($adminBase . '/index.php?page=member-add') ?>" class="btn-primary inline-flex items-center gap-2 whitespace-nowrap flex-shrink-0">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
            <span>Add Member</span>
        </a>
    </div>
    <?php $pageHeaderActions = ob_get_clean(); require __DIR__ . '/components/page-header.php'; ?>

    <!-- Advanced Filters -->
    <div class="mb-8 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <form method="GET" class="space-y-6 p-4 sm:p-5">
            <input type="hidden" name="page" value="members">
            <input type="hidden" name="p" value="1">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 items-end">
                <div>
                    <label class="ta-label mb-2">Gender</label>
                    <select name="gender" class="ta-select w-full">
                        <option value="all" <?= $gender === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="male" <?= $gender === 'male' ? 'selected' : '' ?>>Male</option>
                        <option value="female" <?= $gender === 'female' ? 'selected' : '' ?>>Female</option>
                        <option value="other" <?= $gender === 'other' ? 'selected' : '' ?>>Other</option>
                        <option value="unassigned" <?= $gender === 'unassigned' ? 'selected' : '' ?>>No gender assigned</option>
                    </select>
                </div>

                <div>
                    <label class="ta-label mb-2">Status</label>
                    <select name="status" class="ta-select w-full">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>

                <div>
                    <label class="ta-label mb-2">Tag</label>
                    <select name="tag" class="ta-select w-full">
                        <option value="all" <?= $tagFilter === 'all' ? 'selected' : '' ?>>All Tags</option>
                        <option value="__none__" <?= $tagFilter === '__none__' ? 'selected' : '' ?>>No tags</option>
                        <?php foreach ($allTags as $tag): ?>
                            <option value="<?= e($tag['name']) ?>" <?= $tagFilter === $tag['name'] ? 'selected' : '' ?>>
                                <?= e($tag['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="ta-label mb-2">Group</label>
                    <select name="group" class="ta-select w-full">
                        <option value="all" <?= $groupFilter === 'all' ? 'selected' : '' ?>>All Groups</option>
                        <option value="__none__" <?= $groupFilter === '__none__' ? 'selected' : '' ?>>No groups</option>
                        <?php foreach ($allGroups as $group): ?>
                            <option value="<?= e($group['name']) ?>" <?= $groupFilter === $group['name'] ? 'selected' : '' ?>>
                                <?= e($group['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="lg:col-span-2">
                    <label class="ta-label mb-2">Search</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                        </span>
                        <input
                            type="text"
                            name="search"
                            value="<?= e($search) ?>"
                            placeholder="Name, email, or phone (any format)..."
                            class="ta-input ta-input-with-icon w-full pl-10"
                        >
                    </div>
                </div>

                <div class="lg:col-span-3 flex items-stretch gap-2">
                    <button type="submit" class="btn-primary flex-1">
                        Apply Filters
                    </button>
                    <a href="<?= e($adminBase . '/?page=members') ?>" class="btn-secondary inline-flex items-center justify-center px-5 shrink-0">
                        Reset
                    </a>
                </div>
            </div>

            <!-- Bulk Actions -->
            <div x-show="selectedMembers.length > 0" x-transition class="pt-4 border-t border-gray-100 dark:border-gray-800">
                <div class="flex items-center justify-between bg-brand-50 rounded-xl p-4">
                    <span class="text-sm font-bold text-brand-900">
                        <span x-text="selectedMembers.length"></span> members selected
                    </span>
                    <div class="flex gap-2">
                        <button type="button" @click="bulkAssignTag()" class="btn-secondary text-purple-600 border-purple-100 hover:bg-purple-50 text-xs py-1.5">
                            Assign Tag
                        </button>
                        <button type="button" @click="bulkRemoveTag()" class="btn-secondary text-rose-600 border-rose-100 hover:bg-rose-50 text-xs py-1.5">
                            Remove Tag
                        </button>
                        <button type="button" @click="bulkAssignGroup()" class="btn-secondary text-emerald-600 border-emerald-100 hover:bg-emerald-50 text-xs py-1.5">
                            Add to Group
                        </button>
                        <button type="button" @click="bulkRemoveGroup()" class="btn-secondary text-rose-600 border-rose-100 hover:bg-rose-50 text-xs py-1.5">
                            Remove from Group
                        </button>
                        <button type="button" @click="openBulkEmailModal()" class="btn-primary shadow-sm text-xs py-1.5 flex items-center gap-1.5">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                            Send Email
                        </button>
                        <button type="button" @click="selectedMembers = []" class="text-gray-500 hover:text-gray-700 font-bold text-xs uppercase tracking-widest px-3 dark:text-gray-200">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <!-- Members Table -->
    <div class="mb-12 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <?php if (empty($members)): ?>
            <div class="p-16 text-center">
                <div class="w-16 h-16 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-4 dark:bg-gray-800">
                    <svg class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                </div>
                <p class="text-gray-500 font-medium mb-6 dark:text-gray-400">No members found matching your search.</p>
                <div class="flex justify-center gap-3">
                    <a href="<?= e($adminBase . '/index.php?page=member-add') ?>" class="btn-primary">Add Member</a>
                    <button @click="showImportModal = true" class="btn-secondary bg-gray-100 dark:bg-gray-800">Import CSV</button>
                </div>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto custom-scrollbar px-4 pb-3 pt-4 sm:px-6">
                <table class="min-w-full">
                    <thead>
                        <tr class="border-y border-gray-100 dark:border-gray-800">
                            <th class="w-12 py-3 pr-4 text-center sm:w-14">
                                <p class="sr-only">Select</p>
                                <input 
                                    type="checkbox" 
                                    @change="toggleSelectAll($event)"
                                    class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                    aria-label="Select all members on this page"
                                >
                            </th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Member</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Contact Info</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Tags & Groups</p></th>
                            <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Activity</p></th>
                            <th class="py-3 pr-4 text-right"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Actions</p></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        <?php foreach ($members as $member): ?>
                            <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.02] dark:bg-gray-800">
                                <td class="py-3 pr-4 text-center align-middle">
                                    <input 
                                        type="checkbox" 
                                        :value="<?= $member['id'] ?>"
                                        :checked="selectedMembers.includes(<?= $member['id'] ?>)"
                                        @change="toggleMember(<?= $member['id'] ?>)"
                                        class="rounded border-gray-300 text-brand-600 focus:ring-brand-500"
                                        aria-label="Select member"
                                    >
                                </td>
                                <td class="py-3 pr-4">
                                    <a href="<?= e($adminBase . '/?page=member-details&id=' . $member['id']) ?>" class="group flex items-center gap-3">
                                        <span class="ta-avatar ta-avatar-sm bg-brand-100 text-brand-700"><?= strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1)) ?></span>
                                        <div class="min-w-0">
                                            <span class="block text-theme-sm font-medium text-gray-800 group-hover:text-brand-600 dark:text-white/90"><?= e($member['first_name'] . ' ' . $member['last_name']) ?></span>
                                            <?php if (!empty($member['email'])): ?>
                                            <span class="block text-theme-xs text-gray-500 dark:text-gray-400"><?= e($member['email']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </a>
                                    <div class="mt-1 pl-11 text-[10px] font-bold uppercase tracking-wider text-gray-400"><?= ucfirst($member['gender'] ?: 'Unspecified') ?></div>
                                </td>
                                <td class="py-3 pr-4 text-theme-sm text-gray-700 dark:text-gray-300">
                                    <div class="text-sm text-gray-900 dark:text-white"><?= $member['email'] ? e($member['email']) : '<span class="text-gray-400 italic">No email</span>' ?></div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400"><?= $member['phone'] ? e($member['phone']) : '<span class="text-gray-400">No phone</span>' ?></div>
                                </td>
                                <td>
                                    <div class="flex flex-wrap gap-1">
                                        <?php 
                                        // Parse tags data (format: id:::name:::color)
                                        $tagsData = [];
                                        if (!empty($member['tags_data'])) {
                                            $tagsArray = explode('|||', $member['tags_data']);
                                            foreach ($tagsArray as $tagStr) {
                                                $parts = explode(':::', $tagStr);
                                                if (count($parts) >= 2) {
                                                    $tagsData[] = [
                                                        'id' => $parts[0] ?? '',
                                                        'name' => $parts[1] ?? '',
                                                        'color' => $parts[2] ?? '#6366F1'
                                                    ];
                                                }
                                            }
                                        } elseif (!empty($member['tags'])) {
                                            foreach (explode(',', $member['tags']) as $tagName) {
                                                $trimmed = trim($tagName);
                                                if ($trimmed) $tagsData[] = ['name' => $trimmed, 'color' => '#6366F1'];
                                            }
                                        }
                                        
                                        // Parse groups data (format: id:::name:::color)
                                        $groupsData = [];
                                        if (!empty($member['groups_data'])) {
                                            $groupsArray = explode('|||', $member['groups_data']);
                                            foreach ($groupsArray as $groupStr) {
                                                $parts = explode(':::', $groupStr);
                                                if (count($parts) >= 2) {
                                                    $groupsData[] = [
                                                        'id' => $parts[0] ?? '',
                                                        'name' => $parts[1] ?? '',
                                                        'color' => $parts[2] ?? '#10B981'
                                                    ];
                                                }
                                            }
                                        } elseif (!empty($member['groups'])) {
                                            foreach (explode(',', $member['groups']) as $groupName) {
                                                $trimmed = trim($groupName);
                                                if ($trimmed) $groupsData[] = ['name' => $trimmed, 'color' => '#10B981'];
                                            }
                                        }
                                        
                                        // Display tags
                                        foreach ($tagsData as $tag): 
                                            $tagColor = $tag['color'] ?? '#6366F1';
                                        ?>
                                            <span class="status-badge bg-purple-50 text-purple-700 border-purple-100 flex items-center gap-1.5">
                                                <div class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background-color: <?= e($tagColor) ?>"></div>
                                                <span><?= e($tag['name']) ?></span>
                                            </span>
                                        <?php endforeach; ?>
                                        
                                        <!-- Display groups -->
                                        <?php foreach ($groupsData as $group): 
                                            $groupColor = $group['color'] ?? '#10B981';
                                        ?>
                                            <span class="status-badge bg-emerald-50 text-emerald-700 border-emerald-100 flex items-center gap-1.5">
                                                <div class="w-1.5 h-1.5 rounded-full flex-shrink-0" style="background-color: <?= e($groupColor) ?>"></div>
                                                <span><?= e($group['name']) ?></span>
                                            </span>
                                        <?php endforeach; ?>
                                        
                                        <?php if (empty($tagsData) && empty($groupsData)): ?>
                                            <span class="text-xs text-gray-400 italic">None</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="flex items-center space-x-2">
                                        <div class="text-sm font-bold text-gray-900 dark:text-white"><?= (int)$member['total_events'] ?></div>
                                        <div class="text-[10px] text-gray-400 uppercase font-bold">Events</div>
                                    </div>
                                    <?php if ($member['last_attendance']): ?>
                                        <div class="text-[10px] text-gray-400">Last: <?= date('M j, Y', strtotime($member['last_attendance'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-right">
                                    <div class="flex justify-end gap-1">
                                        <a href="<?= e($adminBase . '/index.php?page=member-edit&id=' . (int) $member['id']) ?>" class="p-2 text-gray-400 hover:text-brand-600 transition-colors" title="Edit">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                                        </a>
                                        <button @click="manageTags(<?= $member['id'] ?>)" class="p-2 text-gray-400 hover:text-purple-600 transition-colors" title="Tags">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path></svg>
                                        </button>
                                        <button @click="deleteMember(<?= $member['id'] ?>, '<?= e($member['first_name'] . ' ' . $member['last_name']) ?>')" class="p-2 text-gray-400 hover:text-rose-600 transition-colors" title="Delete">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination Controls -->
            <?php if ($pagination['total_pages'] > 1): ?>
                <div class="border-t border-gray-200 px-6 py-4 bg-gray-50/50 dark:bg-gray-800 dark:border-gray-700">
                    <div class="flex flex-col sm:flex-row items-center justify-between gap-4">
                        <div class="text-sm text-gray-600 dark:text-gray-300">
                            Showing <span class="font-bold text-gray-900 dark:text-white"><?= $pagination['offset'] + 1 ?></span> to 
                            <span class="font-bold text-gray-900 dark:text-white"><?= min($pagination['offset'] + $pagination['per_page'], $pagination['total']) ?></span> of 
                            <span class="font-bold text-gray-900 dark:text-white"><?= number_format($pagination['total']) ?></span> members
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <?php
                            // Build query string preserving filters
                            $queryParams = ['page' => 'members'];
                            if ($gender !== 'all') $queryParams['gender'] = $gender;
                            if ($status !== 'all') $queryParams['status'] = $status;
                            if ($search) $queryParams['search'] = $search;
                            if ($tagFilter !== 'all') $queryParams['tag'] = $tagFilter;
                            if ($groupFilter !== 'all') $queryParams['group'] = $groupFilter;
                            if ($perPage != 25) $queryParams['per_page'] = $perPage;
                            
                            $baseUrl = $adminBase . '/?';
                            ?>
                            
                            <!-- Previous Button -->
                            <?php if ($pagination['has_prev']): 
                                $queryParams['p'] = $pagination['current_page'] - 1;
                                $prevUrl = $baseUrl . http_build_query($queryParams);
                            ?>
                                <a href="<?= e($prevUrl) ?>" 
                                   class="ta-page-btn">
                                    <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                    </svg>
                                    Previous
                                </a>
                            <?php else: ?>
                                <span class="btn-secondary bg-gray-50 text-gray-400 px-3 py-2 cursor-not-allowed opacity-50 dark:bg-gray-800">
                                    <svg class="w-4 h-4 inline-block mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                                    </svg>
                                    Previous
                                </span>
                            <?php endif; ?>
                            
                            <!-- Page Numbers -->
                            <div class="flex items-center gap-1">
                                <?php
                                $startPage = max(1, $pagination['current_page'] - 2);
                                $endPage = min($pagination['total_pages'], $pagination['current_page'] + 2);
                                
                                // Show first page if not in range
                                if ($startPage > 1): 
                                    $queryParams['p'] = 1;
                                    $firstUrl = $baseUrl . http_build_query($queryParams);
                                ?>
                                    <a href="<?= e($firstUrl) ?>" 
                                       class="ta-page-btn min-w-[40px] text-center">
                                        1
                                    </a>
                                    <?php if ($startPage > 2): ?>
                                        <span class="px-2 text-gray-400">...</span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $startPage; $i <= $endPage; $i++): 
                                    $queryParams['p'] = $i;
                                    $pageUrl = $baseUrl . http_build_query($queryParams);
                                ?>
                                    <?php if ($i == $pagination['current_page']): ?>
                                        <span class="btn-primary border border-brand-600 px-3 py-2 text-sm min-w-[40px] text-center font-bold">
                                            <?= $i ?>
                                        </span>
                                    <?php else: ?>
                                        <a href="<?= e($pageUrl) ?>" 
                                           class="ta-page-btn min-w-[40px] text-center">
                                            <?= $i ?>
                                        </a>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                
                                <!-- Show last page if not in range -->
                                <?php if ($endPage < $pagination['total_pages']): 
                                    $queryParams['p'] = $pagination['total_pages'];
                                    $lastUrl = $baseUrl . http_build_query($queryParams);
                                ?>
                                    <?php if ($endPage < $pagination['total_pages'] - 1): ?>
                                        <span class="px-2 text-gray-400">...</span>
                                    <?php endif; ?>
                                    <a href="<?= e($lastUrl) ?>" 
                                       class="ta-page-btn min-w-[40px] text-center">
                                        <?= $pagination['total_pages'] ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Next Button -->
                            <?php if ($pagination['has_next']): 
                                $queryParams['p'] = $pagination['current_page'] + 1;
                                $nextUrl = $baseUrl . http_build_query($queryParams);
                            ?>
                                <a href="<?= e($nextUrl) ?>" 
                                   class="ta-page-btn">
                                    Next
                                    <svg class="w-4 h-4 inline-block ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                    </svg>
                                </a>
                            <?php else: ?>
                                <span class="btn-secondary bg-gray-50 text-gray-400 px-3 py-2 cursor-not-allowed opacity-50 dark:bg-gray-800">
                                    Next
                                    <svg class="w-4 h-4 inline-block ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                                    </svg>
                                </span>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Items per page selector -->
                        <div class="flex items-center gap-2 text-sm">
                            <label class="text-gray-600 dark:text-gray-300">Per page:</label>
                            <select onchange="changePerPage(this.value)" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none dark:border-gray-700">
                                <option value="10" <?= $perPage == 10 ? 'selected' : '' ?>>10</option>
                                <option value="25" <?= $perPage == 25 ? 'selected' : '' ?>>25</option>
                                <option value="50" <?= $perPage == 50 ? 'selected' : '' ?>>50</option>
                                <option value="100" <?= $perPage == 100 ? 'selected' : '' ?>>100</option>
                            </select>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- CREATE/EDIT MEMBER MODAL -->
    <?php
    ob_start();
    ?>
    <form @submit.prevent="saveMember()" class="space-y-6">
        <!-- Error Messages -->
        <div x-show="formErrors.length > 0" x-transition class="bg-rose-50 border border-rose-100 text-rose-700 px-4 py-3 rounded-xl mb-6">
            <ul class="list-disc list-inside text-sm font-medium">
                <template x-for="error in formErrors" :key="error">
                    <li x-text="error"></li>
                </template>
            </ul>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">First Name</label>
                <input 
                    type="text" 
                    x-model="memberForm.first_name"
                    placeholder="e.g. John"
                    class="w-full border border-gray-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all font-medium dark:border-gray-700"
                    required
                >
            </div>
            
            <div>
                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Last Name</label>
                <input 
                    type="text" 
                    x-model="memberForm.last_name"
                    placeholder="e.g. Doe"
                    class="w-full border border-gray-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all font-medium dark:border-gray-700"
                    required
                >
            </div>
        </div>
        
        <div>
            <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Email Address</label>
            <div class="relative">
                <span class="absolute left-3 top-3.5 text-gray-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 12a4 4 0 10-8 0 4 4 0 008 0zm0 0v1.5a2.5 2.5 0 005 0V12a9 9 0 10-9 9m4.5-1.206a8.959 8.959 0 01-4.5 1.207"></path></svg>
                </span>
                <input 
                    type="email" 
                    x-model="memberForm.email"
                    placeholder="john@example.com"
                    class="w-full border border-gray-200 rounded-xl pl-10 pr-4 py-3 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all font-medium dark:border-gray-700"
                    required
                >
            </div>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Phone Number</label>
                <div class="relative">
                    <span class="absolute left-3 top-3.5 text-gray-400">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                    </span>
                    <input 
                        type="tel" 
                        x-model="memberForm.phone"
                        placeholder="(555) 000-0000"
                        class="w-full border border-gray-200 rounded-xl pl-10 pr-4 py-3 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all font-medium dark:border-gray-700"
                    >
                </div>
            </div>
            
            <div>
                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Gender</label>
                <select 
                    x-model="memberForm.gender"
                    class="w-full border border-gray-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all font-medium dark:border-gray-700"
                >
                    <option value="">Select Gender</option>
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                    <option value="other">Other</option>
                </select>
            </div>
        </div>
        
        <div>
            <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Date of Birth</label>
            <div class="relative">
                <span class="absolute left-3 top-3.5 text-gray-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                </span>
                <input 
                    type="date" 
                    x-model="memberForm.date_of_birth"
                    class="w-full border border-gray-200 rounded-xl pl-10 pr-4 py-3 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all font-medium dark:border-gray-700"
                >
            </div>
            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">Optional - Used for age calculations and demographics</p>
        </div>
        
        <div class="flex items-center justify-end gap-3 pt-6 border-t border-gray-100 dark:border-gray-800">
            <button 
                type="button"
                @click="showMemberModal = false"
                class="btn-secondary"
            >
                Cancel
            </button>
            <button 
                type="submit" 
                :disabled="saving"
                class="btn-primary min-w-[140px]"
            >
                <div class="flex items-center justify-center">
                    <svg x-show="saving" class="animate-spin -ml-1 mr-3 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    <span x-text="saving ? 'Saving...' : (memberForm.id ? 'Save Changes' : 'Add Member')"></span>
                </div>
            </button>
        </div>
    </form>
    <?php
    $modalContent = ob_get_clean();
    $modalName = 'showMemberModal';
    $modalTitleDynamic = "memberForm.id ? 'Edit Member' : 'Add Member'";
    $maxWidth = '2xl';
    include __DIR__ . '/components/modal-base.php';
    ?>

    <!-- TAGS MANAGER MODAL -->
    <?php
    ob_start();
    ?>
    <div class="space-y-6">
        <form @submit.prevent="createTag()" class="p-4 bg-gray-50 rounded-2xl border border-gray-100 flex gap-2 dark:bg-gray-800 dark:border-gray-800">
            <input 
                type="text" 
                x-model="newTag.name" 
                placeholder="New tag name..."
                class="ta-input flex-1"
                required
            >
            <input 
                type="color" 
                x-model="newTag.color"
                class="w-12 h-10 border border-gray-200 rounded-xl p-1 cursor-pointer dark:border-gray-700"
            >
            <button type="submit" class="btn-primary">Add</button>
        </form>

        <div class="space-y-2 max-h-96 overflow-y-auto pr-2">
            <template x-for="tag in tags" :key="tag.id">
                <div class="flex items-center justify-between p-3 bg-white rounded-xl border border-gray-100 group hover:border-brand-100 transition-all dark:bg-gray-800 dark:border-gray-800">
                    <div class="flex items-center space-x-3">
                        <div class="w-3 h-3 rounded-full" :style="'background-color: ' + tag.color"></div>
                        <span class="font-bold text-gray-700 dark:text-gray-200" x-text="tag.name"></span>
                    </div>
                    <button @click="deleteTag(tag.id, tag.name)" class="p-2 text-gray-400 hover:text-rose-600 opacity-0 group-hover:opacity-100 transition-all">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                    </button>
                </div>
            </template>
        </div>
    </div>
    <?php
    $modalContent = ob_get_clean();
    $modalName = 'showTagsManager';
    $modalTitle = 'Manage Member Tags';
    $maxWidth = 'md';
    include __DIR__ . '/components/modal-base.php';
    ?>

    <!-- GROUPS MANAGER MODAL -->
    <?php
    ob_start();
    ?>
    <div class="space-y-6">
        <form @submit.prevent="createGroup()" class="p-5 bg-gray-50 rounded-2xl border border-gray-100 space-y-4 dark:bg-gray-800 dark:border-gray-800">
            <input 
                type="text" 
                x-model="newGroup.name" 
                placeholder="Group name..."
                class="ta-input w-full"
                required
            >
            <textarea 
                x-model="newGroup.description" 
                placeholder="Description (optional)"
                rows="2"
                class="ta-input w-full"
            ></textarea>
            <div class="flex gap-2">
                <input 
                    type="color" 
                    x-model="newGroup.color"
                    class="w-12 h-10 border border-gray-200 rounded-xl p-1 cursor-pointer dark:border-gray-700"
                >
                <button type="submit" class="flex-1 btn-primary bg-success-600 hover:bg-success-700">Create Group</button>
            </div>
        </form>

        <div class="space-y-2 max-h-96 overflow-y-auto pr-2">
            <template x-for="group in groups" :key="group.id">
                <div class="p-4 bg-white rounded-xl border border-gray-100 group hover:border-emerald-100 transition-all dark:bg-gray-800 dark:border-gray-800">
                    <div class="flex items-center justify-between mb-1">
                        <div class="flex items-center space-x-3">
                            <div class="w-3 h-3 rounded-full" :style="'background-color: ' + group.color"></div>
                            <span class="font-bold text-gray-900 dark:text-white" x-text="group.name"></span>
                        </div>
                        <button @click="deleteGroup(group.id, group.name)" class="p-2 text-gray-400 hover:text-rose-600 opacity-0 group-hover:opacity-100 transition-all">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        </button>
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400" x-text="group.description || 'No description'"></p>
                </div>
            </template>
        </div>
    </div>
    <?php
    $modalContent = ob_get_clean();
    $modalName = 'showGroupsManager';
    $modalTitle = 'Manage Member Groups';
    $maxWidth = 'md';
    include __DIR__ . '/components/modal-base.php';
    ?>

    <!-- INDIVIDUAL MEMBER TAGS MODAL -->
    <?php
    ob_start();
    ?>
    <div class="space-y-6" x-data="memberTagsApp()" x-init="init()">
        <div class="p-4 bg-purple-50 border border-purple-100 rounded-2xl">
            <p class="text-sm font-bold text-purple-900">
                Managing tags for: <span x-text="$root.currentMemberName || 'Member'"></span>
            </p>
        </div>

        <!-- Current Tags -->
        <div>
            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-3">Current Tags</h3>
            <div x-show="loading" class="text-center py-8">
                <div class="inline-block animate-spin w-6 h-6 border-4 border-purple-500 border-t-transparent rounded-full"></div>
            </div>
            <div x-show="!loading && memberTags.length === 0" class="text-center py-8 text-gray-400 text-sm">
                No tags assigned
            </div>
            <div x-show="!loading && memberTags.length > 0" class="flex flex-wrap gap-2">
                <template x-for="tag in memberTags" :key="tag.id">
                    <div class="flex items-center gap-2 bg-purple-50 border border-purple-200 rounded-xl px-3 py-2">
                        <div class="w-2 h-2 rounded-full" :style="'background-color: ' + tag.color"></div>
                        <span class="text-sm font-bold text-purple-700" x-text="tag.name"></span>
                        <button 
                            @click="removeTag(tag.id)"
                            class="text-purple-400 hover:text-purple-600 transition-colors"
                            title="Remove tag"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                        </button>
                    </div>
                </template>
            </div>
        </div>

        <!-- Available Tags -->
        <div>
            <h3 class="text-sm font-bold text-gray-400 uppercase tracking-wider mb-3">Available Tags</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 max-h-64 overflow-y-auto pr-2">
                <template x-for="tag in availableTags" :key="tag.id">
                    <button 
                        @click="assignTag(tag.id)"
                        :disabled="isTagAssigned(tag.id)"
                        class="flex items-center gap-2 p-3 rounded-xl border transition-all text-left disabled:opacity-50 disabled:cursor-not-allowed"
                        :class="isTagAssigned(tag.id) ? 'border-gray-200 bg-gray-50' : 'border-gray-200 bg-white hover:border-purple-300 hover:bg-purple-50'"
                    >
                        <div class="w-3 h-3 rounded-full flex-shrink-0" :style="'background-color: ' + tag.color"></div>
                        <span class="text-sm font-bold text-gray-700 dark:text-gray-200" x-text="tag.name"></span>
                        <template x-if="isTagAssigned(tag.id)">
                            <svg class="w-4 h-4 text-purple-600 ml-auto" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                        </template>
                    </button>
                </template>
            </div>
        </div>
    </div>
    <?php
    $modalContent = ob_get_clean();
    $modalName = 'showMemberTagsModal';
    $modalTitleDynamic = "'Manage Tags'";
    $maxWidth = 'md';
    include __DIR__ . '/components/modal-base.php';
    ?>

    <!-- BULK ACTION MODAL (Tags/Groups Selection) -->
    <?php
    ob_start();
    ?>
    <div class="space-y-6">
        <div class="p-4 bg-brand-50 border border-brand-100 rounded-2xl flex items-start gap-3">
            <svg class="w-5 h-5 text-brand-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <div>
                <p class="text-sm font-bold text-brand-900">
                    Applying to <span x-text="selectedMembers.length"></span> selected members
                </p>
                <p class="text-xs text-brand-700 mt-0.5" x-show="bulkActionType === 'tag' && bulkActionMode === 'assign'">Select one or more tags to assign.</p>
                <p class="text-xs text-brand-700 mt-0.5" x-show="bulkActionType === 'tag' && bulkActionMode === 'remove'">Select one or more tags to remove from selected members.</p>
                <p class="text-xs text-brand-700 mt-0.5" x-show="bulkActionType === 'group' && bulkActionMode === 'assign'">Select groups to add members to. Checked groups will be assigned.</p>
                <p class="text-xs text-brand-700 mt-0.5" x-show="bulkActionType === 'group' && bulkActionMode === 'remove'">Select one or more groups to remove members from.</p>
            </div>
        </div>





        <div class="space-y-3">
            <div class="flex gap-2">
                <div class="relative flex-1">
                    <input 
                        type="text" 
                        x-model="bulkSearch" 
                        :placeholder="'Search or type to create new ' + bulkActionType + '...'"
                        class="w-full border border-gray-200 rounded-2xl pl-10 pr-4 py-3 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all text-sm dark:border-gray-700"
                    >
                    <span class="absolute left-3 top-3.5 text-gray-400">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                    </span>
                </div>
                <button 
                    x-show="bulkSearch.trim() && !(bulkActionType === 'tag' ? tags : groups).find(i => i.name.toLowerCase() === bulkSearch.toLowerCase())"
                    @click="createAndAssignBulk()"
                    class="btn-primary px-4 whitespace-nowrap"
                >
                    Create New
                </button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-[250px] overflow-y-auto pr-2 custom-scrollbar p-1">
                <!-- Tags Selection -->
                <template x-if="bulkActionType === 'tag'">
                    <template x-for="tag in tags.filter(t => t.name.toLowerCase().includes(bulkSearch.toLowerCase()))" :key="tag.id">
                        <button 
                            @click="toggleBulkItem(tag.id)"
                            :class="selectedBulkItems.includes(tag.id) 
                                ? (bulkActionMode === 'remove' ? 'border-rose-500 bg-rose-50 ring-2 ring-rose-500/20' : 'border-brand-500 bg-brand-50 ring-2 ring-brand-500/20')
                                : areAllMembersHaveTag(tag.id) && bulkActionMode === 'remove'
                                    ? 'border-amber-300 bg-amber-50 hover:border-amber-400'
                                    : 'border-gray-100 bg-white hover:border-brand-200'"
                            class="p-4 rounded-2xl border text-left transition-all group relative overflow-hidden h-[72px]"
                        >
                            <div class="flex items-center gap-3 relative z-10">
                                <div class="w-3 h-3 rounded-full flex-shrink-0" :style="'background-color: ' + tag.color"></div>
                                <div class="flex-1 min-w-0">
                                    <span class="font-bold text-gray-900 block truncate dark:text-white" x-text="tag.name"></span>
                                    <span x-show="areAllMembersHaveTag(tag.id) && bulkActionMode === 'remove' && !selectedBulkItems.includes(tag.id)" class="text-[10px] text-amber-600 font-bold block mt-0.5">Currently assigned (click to remove)</span>
                                </div>
                            </div>
                            <div x-show="selectedBulkItems.includes(tag.id)" class="absolute top-2 right-2">
                                <svg class="w-5 h-5" :class="bulkActionMode === 'remove' ? 'text-rose-600' : 'text-brand-600'" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                            </div>
                            <div x-show="areAllMembersHaveTag(tag.id) && bulkActionMode === 'remove' && !selectedBulkItems.includes(tag.id)" class="absolute top-2 right-2">
                                <svg class="w-4 h-4 text-amber-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"></path></svg>
                            </div>
                        </button>
                    </template>
                </template>

                <!-- Groups Selection -->
                <template x-if="bulkActionType === 'group'">
                    <template x-for="group in groups.filter(g => g.name.toLowerCase().includes(bulkSearch.toLowerCase()))" :key="group.id">
                        <button 
                            @click="toggleBulkItem(group.id)"
                            :class="selectedBulkItems.includes(group.id) 
                                ? (bulkActionMode === 'remove' ? 'border-rose-500 bg-rose-50 ring-2 ring-rose-500/20' : 'border-emerald-500 bg-emerald-50 ring-2 ring-emerald-500/20')
                                : areAllMembersInGroup(group.id) 
                                    ? (bulkActionMode === 'remove' ? 'border-amber-300 bg-amber-50 hover:border-amber-400' : 'border-amber-300 bg-amber-50 hover:border-amber-400')
                                    : 'border-gray-100 bg-white hover:border-emerald-200'"
                            class="p-4 rounded-2xl border text-left transition-all group relative overflow-hidden h-[72px]"
                        >
                            <div class="flex items-center gap-3 relative z-10">
                                <div class="w-3 h-3 rounded-full flex-shrink-0" :style="'background-color: ' + group.color"></div>
                                <div class="flex-1 min-w-0">
                                    <span class="font-bold text-gray-900 block truncate dark:text-white" x-text="group.name"></span>
                                    <span class="text-[10px] text-gray-500 uppercase font-black dark:text-gray-400" x-text="group.description ? 'Has Description' : 'No description'"></span>
                                    <span x-show="areAllMembersInGroup(group.id) && !selectedBulkItems.includes(group.id)" class="text-[10px] text-amber-600 font-bold block mt-0.5">Currently in (click to remove)</span>
                                </div>
                            </div>
                            <div x-show="selectedBulkItems.includes(group.id)" class="absolute top-2 right-2">
                                <svg class="w-5 h-5" :class="bulkActionMode === 'remove' ? 'text-rose-600' : 'text-emerald-600'" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path></svg>
                            </div>
                            <div x-show="areAllMembersInGroup(group.id) && !selectedBulkItems.includes(group.id)" class="absolute top-2 right-2">
                                <svg class="w-4 h-4 text-amber-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-11a1 1 0 10-2 0v2H7a1 1 0 100 2h2v2a1 1 0 102 0v-2h2a1 1 0 100-2h-2V7z" clip-rule="evenodd"></path></svg>
                            </div>
                        </button>
                    </template>
                </template>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 pt-6 border-t border-gray-100 dark:border-gray-800">
            <button 
                type="button" 
                @click="showBulkActionModal = false"
                class="px-6 py-2.5 text-sm font-bold text-gray-500 hover:text-gray-700 transition-colors dark:text-gray-200"
            >
                Cancel
            </button>
            <button 
                type="button"
                @click="confirmBulkAction()"
                :disabled="selectedBulkItems.length === 0 || saving"
                :class="bulkActionType === 'tag' && bulkActionMode === 'remove' ? 'bg-rose-600 hover:bg-rose-700' : bulkActionType === 'tag' ? 'bg-brand-600 hover:bg-brand-700' : bulkActionType === 'group' && bulkActionMode === 'remove' ? 'bg-rose-600 hover:bg-rose-700' : 'bg-emerald-600 hover:bg-emerald-700'"
                class="inline-flex items-center justify-center px-5 py-2.5 text-sm font-semibold rounded-lg cursor-pointer transition-colors border border-transparent text-white shadow-lg disabled:opacity-50 disabled:cursor-not-allowed min-w-[180px]"
            >
                <div class="flex items-center justify-center">
                    <svg x-show="saving" class="animate-spin -ml-1 mr-3 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    <span x-text="saving ? 'Applying...' : 'Apply Selection (' + selectedBulkItems.length + ')'"></span>
                </div>
            </button>
        </div>
    </div>
    <?php
    $modalContent = ob_get_clean();
    $modalName = 'showBulkActionModal';
    $modalTitleDynamic = "bulkActionType === 'tag' && bulkActionMode === 'remove' ? 'Remove Tags from Members' : bulkActionType === 'tag' ? 'Assign Tags to Members' : bulkActionType === 'group' && bulkActionMode === 'remove' ? 'Remove Members from Groups' : 'Add Members to Groups'";
    $maxWidth = 'xl';
    include __DIR__ . '/components/modal-base.php';
    ?>

    <!-- Import Members Modal - Using Alpine.js for consistency -->
    <div x-show="showImportModal" 
         x-cloak
         @keydown.escape.window="showImportModal = false"
         class="fixed inset-0 z-[10000] flex items-center justify-center p-4 overflow-y-auto"
         style="display: none;">
        
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px] transition-opacity"
             style="z-index: 1;"
             @click="showImportModal = false"></div>
        
        <!-- Modal -->
        <div class="relative max-h-[80vh] w-full max-w-xl transform overflow-y-auto rounded-2xl border border-gray-200 bg-white shadow-card-lg transition-all dark:bg-gray-800 dark:border-gray-700"
             style="z-index: 2;"
             @click.away="showImportModal = false">
                
                <!-- Header -->
                <div class="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-xl font-extrabold text-gray-900 dark:text-white">Import Members from CSV</h3>
                    <button @click="showImportModal = false" 
                            class="text-gray-400 hover:text-gray-600 transition-colors dark:text-gray-300">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                
                <!-- Content -->
                <div class="p-8">
                    <form id="import-member-form" onsubmit="handleImportMembers(event)" enctype="multipart/form-data" class="space-y-6">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">CSV File Selection</label>
                                <div class="relative">
                                    <input type="file" id="csv_file" name="file" accept=".csv" required
                                           class="w-full px-4 py-8 border-2 border-dashed border-gray-200 rounded-2xl hover:border-brand-400 hover:bg-brand-50/30 transition-all cursor-pointer text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-bold file:bg-brand-600 file:text-white hover:file:bg-brand-700 dark:text-gray-400 dark:border-gray-700">
                                </div>
                                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">Required columns: <code class="bg-gray-100 px-1.5 py-0.5 rounded text-brand-600 font-bold dark:bg-gray-800">first_name</code>, <code class="bg-gray-100 px-1.5 py-0.5 rounded text-brand-600 font-bold dark:bg-gray-800">last_name</code>, <code class="bg-gray-100 px-1.5 py-0.5 rounded text-brand-600 font-bold dark:bg-gray-800">email</code></p>
                            </div>

                            <div class="bg-emerald-50 border border-emerald-100 rounded-2xl p-5">
                                <div class="flex items-center mb-3">
                                    <svg class="w-5 h-5 text-emerald-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                    <h3 class="text-sm font-bold text-emerald-900">CSV Format Example</h3>
                                </div>
                                <pre class="text-[10px] text-emerald-800 font-mono leading-relaxed bg-white/50 p-3 rounded-xl border border-emerald-200/50 dark:bg-gray-800">first_name,last_name,email,phone,gender
John,Doe,john@example.com,5551234567,male
Jane,Smith,jane@example.com,5551112222,female</pre>
                            </div>

                            <div id="import-member-errors" class="text-rose-600 text-sm font-medium"></div>
                        </div>
                        
                        <div class="flex justify-end gap-3 pt-4 border-t border-gray-100 dark:border-gray-800">
                            <button type="button" @click="showImportModal = false"
                                    class="btn-secondary">
                                Cancel
                            </button>
                            <button type="submit"
                                    class="btn-primary min-w-[160px]">
                                Import Members
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo htmlspecialchars($jsBase); ?>api.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo htmlspecialchars($jsBase); ?>toast.js?v=<?php echo time(); ?>"></script>
<script>
// Define these BEFORE Alpine.js initializes
const apiBaseUrl = '<?php echo htmlspecialchars($apiBase); ?>';
const API_BASE_URL = '<?= e($basePath . '/public/api') ?>';
const csrfToken = '<?php echo htmlspecialchars($csrfToken); ?>';

// Store app instance globally for modal access
let membersAppInstance = null;

// Define membersApp function (available both as membersApp() and window.membersApp())
function membersApp() {
    return {
        init() {
            this.$watch('showBulkEmailModal', (open) => {
                if (open) {
                    this.$nextTick(() => setTimeout(() => this.initBulkEmailWysiwyg(), 100));
                }
            });
        },
        initBulkEmailWysiwyg() {
            const ta = document.getElementById('bulk-email-composer-body');
            if (!ta || typeof window.initWYSIWYG !== 'function') return;
            if (!ta.dataset.quillInitialized) {
                window.initWYSIWYG('#bulk-email-composer-body');
                const quill = window.__quillInstances && window.__quillInstances.get(ta);
                if (quill && typeof headcountInitQuillRichToolbar === 'function' && !ta.dataset.bulkRichToolbar) {
                    ta.dataset.bulkRichToolbar = '1';
                    headcountInitQuillRichToolbar(quill, {
                        uploadImageUrl: API_BASE_URL + '/upload-email-image.php',
                        uploadVideoUrl: API_BASE_URL + '/upload-email-video.php',
                        csrfToken: csrfToken
                    });
                }
            }
            ta.value = this.bulkEmailForm.body || '';
            ta.dispatchEvent(new Event('sync-to-quill'));
        },
        flushBulkEmailBodyFromEditor() {
            const ta = document.getElementById('bulk-email-composer-body');
            if (!ta || !window.__quillInstances) return;
            const quill = window.__quillInstances.get(ta);
            if (!quill) return;
            let html = quill.root.innerHTML;
            if (html === '<p><br></p>') html = '';
            this.bulkEmailForm.body = html;
            ta.value = html;
        },
        showMemberModal: false,
        showImportModal: false,
        showTagsManager: false,
        showGroupsManager: false,
        showMemberTagsModal: false,
        currentMemberName: '',
        currentMemberId: null,
        saving: false,
        formErrors: [],
        selectedMembers: [],
        showBulkActionModal: false,
        bulkActionType: '', // 'tag' or 'group'
        bulkActionMode: 'assign', // 'assign' or 'remove'
        selectedBulkItem: null,
        bulkSearch: '',
        selectedBulkItems: [],
        currentGroupMemberships: {}, // { memberId: [groupIds] }
        currentTagMemberships: {}, // { memberId: [tagIds] }
        loadingMemberships: false,
        loadingTags: false,
        showBulkEmailModal: false,
        sendingBulk: false,
        previewSubject: '',
        previewBody: '',
        bulkEmailForm: {
            template_id: '',
            subject: '',
            body: ''
        },
        
        tags: <?php $jt = json_encode($allTags); echo ($jt !== false) ? $jt : '[]'; ?>,
        groups: <?php $jg = json_encode($allGroups); echo ($jg !== false) ? $jg : '[]'; ?>,
        
        newTag: {
            name: '',
            color: '#3B82F6'
        },
        
        newGroup: {
            name: '',
            description: '',
            color: '#10B981'
        },
        
        memberForm: {
            id: null,
            first_name: '',
            last_name: '',
            email: '',
            phone: '',
            gender: '',
            date_of_birth: ''
        },
        
        toggleSelectAll(event) {
            const memberIds = <?= json_encode(array_column($members, 'id')) ?>;
            if (event.target.checked) {
                this.selectedMembers = memberIds;
            } else {
                this.selectedMembers = [];
            }
        },
        
        toggleMember(memberId) {
            const index = this.selectedMembers.indexOf(memberId);
            if (index > -1) {
                this.selectedMembers.splice(index, 1);
            } else {
                this.selectedMembers.push(memberId);
            }
        },
        
        openTagsManager() {
            this.showTagsManager = true;
        },
        
        openGroupsManager() {
            this.showGroupsManager = true;
        },
        
        async createTag() {
            try {
                const response = await fetch(`${API_BASE_URL}/tags.php?action=create`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(this.newTag)
                });
                
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Non-JSON response:', text);
                    alert('Server returned an error. Please check the console for details.');
                    return;
                }
                
                const data = await response.json();
                if (data.success) {
                    this.newTag = { name: '', color: '#3B82F6' };
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to create tag');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred: ' + error.message);
            }
        },
        
        async deleteTag(id, name) {
            const confirmed = await confirmAction({
                title: 'Delete Tag',
                message: `Are you sure you want to delete the tag "${name}"?`,
                type: 'warning',
                okText: 'Delete',
                cancelText: 'Cancel'
            });
            
            if (!confirmed) return;
            
            try {
                const response = await fetch(`${API_BASE_URL}/tags.php?action=delete`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id })
                });
                
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to delete tag');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            }
        },
        
        async createGroup() {
            try {
                const response = await fetch(`${API_BASE_URL}/groups.php?action=create`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(this.newGroup)
                });
                
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Non-JSON response:', text);
                    alert('Server returned an error. Please check the console for details.');
                    return;
                }
                
                const data = await response.json();
                if (data.success) {
                    this.newGroup = { name: '', description: '', color: '#10B981' };
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to create group');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred: ' + error.message);
            }
        },
        
        async deleteGroup(id, name) {
            const confirmed = await confirmAction({
                title: 'Delete Group',
                message: `Are you sure you want to delete the group "${name}"? Members will not be deleted.`,
                type: 'warning',
                okText: 'Delete',
                cancelText: 'Cancel'
            });
            
            if (!confirmed) return;
            
            try {
                const response = await fetch(`${API_BASE_URL}/groups.php?action=delete`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ id })
                });
                
                const data = await response.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'Failed to delete group');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred');
            }
        },
        
        bulkAssignTag() {
            this.bulkActionType = 'tag';
            this.bulkActionMode = 'assign';
            this.selectedBulkItems = [];
            this.showBulkActionModal = true;
            this.bulkSearch = '';
        },
        
        async bulkRemoveTag() {
            this.bulkActionType = 'tag';
            this.bulkActionMode = 'remove';
            this.selectedBulkItems = [];
            this.currentTagMemberships = {};
            this.showBulkActionModal = true;
            this.bulkSearch = '';
            
            // Fetch current tag memberships for selected members
            if (this.selectedMembers.length > 0) {
                await this.fetchCurrentTagMemberships();
            }
        },
        
        async bulkAssignGroup() {
            this.bulkActionType = 'group';
            this.bulkActionMode = 'assign';
            this.selectedBulkItems = [];
            this.currentGroupMemberships = {};
            this.showBulkActionModal = true;
            this.bulkSearch = '';
            
            // Fetch current group memberships for selected members
            if (this.selectedMembers.length > 0) {
                await this.fetchCurrentMemberships();
            }
        },
        
        async bulkRemoveGroup() {
            this.bulkActionType = 'group';
            this.bulkActionMode = 'remove';
            this.selectedBulkItems = [];
            this.currentGroupMemberships = {};
            this.showBulkActionModal = true;
            this.bulkSearch = '';
            
            // Fetch current group memberships for selected members
            if (this.selectedMembers.length > 0) {
                await this.fetchCurrentMemberships();
            }
        },
        
        async fetchCurrentMemberships() {
            if (this.bulkActionType !== 'group' || this.selectedMembers.length === 0) return;
            
            this.loadingMemberships = true;
            try {
                const memberIds = this.selectedMembers.join(',');
                const response = await fetch(`${API_BASE_URL}/groups.php?action=current_memberships&member_ids=${memberIds}`, {
                    headers: {
                        'X-CSRF-Token': csrfToken
                    }
                });
                const data = await response.json();
                
                if (data.success) {
                    this.currentGroupMemberships = data.memberships || {};
                    
                    // Pre-select groups that ALL selected members are in
                    const commonGroups = this.getCommonGroups();
                    this.selectedBulkItems = commonGroups;
                }
            } catch (error) {
                console.error('Error fetching memberships:', error);
            } finally {
                this.loadingMemberships = false;
            }
        },
        
        async fetchCurrentTagMemberships() {
            if (this.bulkActionType !== 'tag' || this.selectedMembers.length === 0) return;
            
            this.loadingTags = true;
            try {
                // Fetch tags for each member
                const memberships = {};
                for (const memberId of this.selectedMembers) {
                    try {
                        const response = await fetch(`${API_BASE_URL}/tags.php?action=member_tags&member_id=${memberId}`);
                        const data = await response.json();
                        if (data.success) {
                            memberships[memberId] = data.tags || [];
                        }
                    } catch (error) {
                        console.error(`Error fetching tags for member ${memberId}:`, error);
                        memberships[memberId] = [];
                    }
                }
                this.currentTagMemberships = memberships;
                
                // Pre-select tags that ALL selected members have
                const commonTags = this.getCommonTags();
                this.selectedBulkItems = commonTags;
            } catch (error) {
                console.error('Error fetching tag memberships:', error);
            } finally {
                this.loadingTags = false;
            }
        },
        
        getCommonTags() {
            if (this.selectedMembers.length === 0) return [];
            
            // Get tags that ALL selected members have
            const memberTagSets = this.selectedMembers.map(memberId => {
                const tags = this.currentTagMemberships[memberId] || [];
                return new Set(tags.map(t => t.id));
            });
            
            if (memberTagSets.length === 0) return [];
            
            // Find intersection of all sets
            let commonTags = Array.from(memberTagSets[0]);
            for (let i = 1; i < memberTagSets.length; i++) {
                commonTags = commonTags.filter(tagId => memberTagSets[i].has(tagId));
            }
            
            return commonTags;
        },
        
        isMemberHasTag(memberId, tagId) {
            const memberTags = this.currentTagMemberships[String(memberId)] || [];
            return memberTags.some(t => String(t.id) === String(tagId));
        },
        
        areAllMembersHaveTag(tagId) {
            if (this.selectedMembers.length === 0) return false;
            return this.selectedMembers.every(memberId => this.isMemberHasTag(memberId, tagId));
        },
        
        getCommonGroups() {
            if (this.selectedMembers.length === 0) return [];
            
            // Get groups that ALL selected members are in
            const memberGroupSets = this.selectedMembers.map(memberId => {
                const groups = this.currentGroupMemberships[memberId] || [];
                return new Set(groups.map(g => g.id));
            });
            
            if (memberGroupSets.length === 0) return [];
            
            // Find intersection of all sets
            let commonGroups = Array.from(memberGroupSets[0]);
            for (let i = 1; i < memberGroupSets.length; i++) {
                commonGroups = commonGroups.filter(groupId => memberGroupSets[i].has(groupId));
            }
            
            return commonGroups;
        },
        
        isMemberInGroup(memberId, groupId) {
            const memberGroups = this.currentGroupMemberships[String(memberId)] || [];
            return memberGroups.some(g => String(g.id) === String(groupId));
        },
        
        areAllMembersInGroup(groupId) {
            if (this.selectedMembers.length === 0) return false;
            return this.selectedMembers.every(memberId => this.isMemberInGroup(memberId, groupId));
        },

        toggleBulkItem(id) {
            const index = this.selectedBulkItems.indexOf(id);
            if (index > -1) {
                this.selectedBulkItems.splice(index, 1);
            } else {
                this.selectedBulkItems.push(id);
            }
        },

        async confirmBulkAction() {
            if (this.selectedBulkItems.length === 0) {
                Toast.error('Please select at least one ' + (this.bulkActionType === 'tag' ? 'tag' : 'group'));
                return;
            }
            
            this.saving = true;
            try {
                if (this.bulkActionType === 'tag') {
                    if (this.bulkActionMode === 'remove') {
                        // Tags: remove
                        let successCount = 0;
                        let removedCount = 0;
                        
                        for (const tagId of this.selectedBulkItems) {
                            const response = await fetch(`${API_BASE_URL}/tags.php?action=bulk_remove`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-Token': csrfToken
                                },
                                body: JSON.stringify({
                                    member_ids: this.selectedMembers,
                                    tag_id: tagId,
                                    csrf_token: csrfToken
                                })
                            });
                            const data = await response.json();
                            if (data.success) {
                                successCount++;
                                removedCount += data.removed || 0;
                            }
                        }

                        if (successCount > 0) {
                            Toast.success(`Successfully removed ${successCount} tag(s) from ${this.selectedMembers.length} members`);
                            this.showBulkActionModal = false;
                            this.selectedMembers = [];
                            setTimeout(() => window.location.reload(), 800);
                        } else {
                            Toast.error('Failed to remove tags');
                        }
                    } else {
                        // Tags: assign
                        const endpointData = { url: `${API_BASE_URL}/tags.php?action=bulk_assign`, key: 'tag_id' };
                        
                        let successCount = 0;
                        for (const itemId of this.selectedBulkItems) {
                            const response = await fetch(endpointData.url, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-Token': csrfToken
                                },
                                body: JSON.stringify({
                                    member_ids: this.selectedMembers,
                                    [endpointData.key]: itemId,
                                    csrf_token: csrfToken
                                })
                            });
                            const data = await response.json();
                            if (data.success) successCount++;
                        }

                        if (successCount > 0) {
                            Toast.success(`Successfully applied ${successCount} tag(s) to ${this.selectedMembers.length} members`);
                            this.showBulkActionModal = false;
                            this.selectedMembers = [];
                            setTimeout(() => window.location.reload(), 800);
                        } else {
                            Toast.error('Failed to apply selection');
                        }
                    }
                } else {
                    // Groups
                    if (this.bulkActionMode === 'remove') {
                        // Groups: remove only
                        let successCount = 0;
                        let removedCount = 0;
                        
                        for (const groupId of this.selectedBulkItems) {
                            const response = await fetch(`${API_BASE_URL}/groups.php?action=bulk_remove`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-Token': csrfToken
                                },
                                body: JSON.stringify({
                                    member_ids: this.selectedMembers,
                                    group_id: groupId,
                                    csrf_token: csrfToken
                                })
                            });
                            const data = await response.json();
                            if (data.success) {
                                successCount++;
                                removedCount += data.removed || 0;
                            }
                        }

                        if (successCount > 0) {
                            Toast.success(`Successfully removed ${successCount} group(s) from ${this.selectedMembers.length} members`);
                            this.showBulkActionModal = false;
                            this.selectedMembers = [];
                            setTimeout(() => window.location.reload(), 800);
                        } else {
                            Toast.error('Failed to remove groups');
                        }
                    } else {
                        // Groups: add or remove based on current membership (original logic)
                        let addedCount = 0;
                        let removedCount = 0;
                        
                        // Get all groups (selected and previously in)
                        const allGroupIds = new Set([
                            ...this.selectedBulkItems,
                            ...Object.values(this.currentGroupMemberships).flat().map(g => g.id)
                        ]);
                        
                        for (const groupId of allGroupIds) {
                            const shouldBeInGroup = this.selectedBulkItems.includes(groupId);
                            
                            for (const memberId of this.selectedMembers) {
                                const isCurrentlyInGroup = this.isMemberInGroup(memberId, groupId);
                                
                                if (shouldBeInGroup && !isCurrentlyInGroup) {
                                    // Add member to group
                                    try {
                                        const response = await fetch(`${API_BASE_URL}/groups.php?action=bulk_add`, {
                                            method: 'POST',
                                            headers: {
                                                'Content-Type': 'application/json',
                                                'X-CSRF-Token': csrfToken
                                            },
                                            body: JSON.stringify({
                                                member_ids: [memberId],
                                                group_id: groupId,
                                                csrf_token: csrfToken
                                            })
                                        });
                                        const data = await response.json();
                                        if (data.success) addedCount++;
                                    } catch (e) {
                                        console.error('Error adding member to group:', e);
                                    }
                                } else if (!shouldBeInGroup && isCurrentlyInGroup) {
                                    // Remove member from group
                                    try {
                                        const response = await fetch(`${API_BASE_URL}/groups.php?action=remove`, {
                                            method: 'POST',
                                            headers: {
                                                'Content-Type': 'application/json',
                                                'X-CSRF-Token': csrfToken
                                            },
                                            body: JSON.stringify({
                                                member_id: memberId,
                                                group_id: groupId,
                                                csrf_token: csrfToken
                                            })
                                        });
                                        const data = await response.json();
                                        if (data.success) removedCount++;
                                    } catch (e) {
                                        console.error('Error removing member from group:', e);
                                    }
                                }
                            }
                        }
                        
                        if (addedCount > 0 || removedCount > 0) {
                            const messages = [];
                            if (addedCount > 0) messages.push(`Added ${addedCount} member(s) to groups`);
                            if (removedCount > 0) messages.push(`Removed ${removedCount} member(s) from groups`);
                            Toast.success(messages.join(', '));
                            this.showBulkActionModal = false;
                            this.selectedMembers = [];
                            setTimeout(() => window.location.reload(), 800);
                        } else {
                            Toast.info('No changes made');
                        }
                    }
                }
            } catch (error) {
                console.error('Bulk action error:', error);
                Toast.error('An error occurred during bulk assignment');
            } finally {
                this.saving = false;
            }
        },

        async createAndAssignBulk() {
            if (!this.bulkSearch.trim()) return;
            
            this.saving = true;
            try {
                const endpoint = this.bulkActionType === 'tag' 
                    ? `${API_BASE_URL}/tags.php?action=create`
                    : `${API_BASE_URL}/groups.php?action=create`;
                
                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        name: this.bulkSearch.trim(),
                        color: this.bulkActionType === 'tag' ? '#6366F1' : '#10B981',
                        csrf_token: csrfToken
                    })
                });
                
                const data = await response.json();
                if (data.success) {
                    const newId = data.tag_id || data.group_id;
                    const newItem = {
                        id: newId,
                        name: this.bulkSearch.trim(),
                        color: this.bulkActionType === 'tag' ? '#6366F1' : '#10B981',
                        organization_id: '<?= $organizationId ?>'
                    };
                    
                    if (this.bulkActionType === 'tag') this.tags.push(newItem);
                    else this.groups.push(newItem);
                    
                    this.toggleBulkItem(newId);
                    this.bulkSearch = '';
                    Toast.success(`Created new ${this.bulkActionType}`);
                } else {
                    Toast.error(data.message || 'Failed to create');
                }
            } catch (error) {
                console.error('Error creating in bulk:', error);
                Toast.error('An error occurred while creating');
            } finally {
                this.saving = false;
            }
        },
        
        manageTags(memberId) {
            // Find member name
            const member = <?= json_encode(array_column($members, null, 'id')) ?>[memberId];
            this.currentMemberId = memberId;
            this.currentMemberName = member ? (member.first_name + ' ' + member.last_name) : 'Member';
            this.showMemberTagsModal = true;
            
            // Trigger load in the modal component
            setTimeout(() => {
                const event = new CustomEvent('loadMemberTags', { detail: { memberId } });
                window.dispatchEvent(event);
            }, 100);
        },
        
        openCreateModal() {
            this.resetForm();
            this.showMemberModal = true;
        },
        
        async openEditModal(memberId) {
            try {
                // Reset form first to clear any previous state
                this.resetForm();
                
                const response = await fetch(`${API_BASE_URL}/members.php?id=${memberId}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken
                    },
                    credentials: 'same-origin'
                });
                
                if (!response.ok) {
                    throw new Error('Failed to load member');
                }
                
                const data = await response.json();
                
                if (data.success && data.data) {
                    const member = data.data;
                    // Explicitly set each field to ensure proper state
                    this.memberForm.id = member.id || null;
                    this.memberForm.first_name = member.first_name || '';
                    this.memberForm.last_name = member.last_name || '';
                    this.memberForm.email = member.email || '';
                    this.memberForm.phone = member.phone || '';
                    // Ensure gender is explicitly set and valid
                    const validGenders = ['male', 'female', 'other'];
                    this.memberForm.gender = (member.gender && validGenders.includes(member.gender)) ? member.gender : '';
                    // Format date_of_birth for date input (YYYY-MM-DD)
                    this.memberForm.date_of_birth = member.date_of_birth || '';
                    this.formErrors = [];
                    this.showMemberModal = true;
                } else {
                    Toast.error(data.message || 'Failed to load member details');
                }
            } catch (error) {
                console.error('Error loading member:', error);
                Toast.error('Failed to load member details');
            }
        },
        
        async saveMember() {
            this.formErrors = [];
            this.saving = true;
            
            try {
                const url = this.memberForm.id 
                    ? `${API_BASE_URL}/members.php?id=${this.memberForm.id}`
                    : `${API_BASE_URL}/members.php`;
                
                const method = this.memberForm.id ? 'PUT' : 'POST';
                
                const response = await fetch(url, {
                    method: method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        ...this.memberForm,
                        csrf_token: csrfToken
                    })
                });
                
                const responseText = await response.text();
                let data;
                
                try {
                    data = JSON.parse(responseText);
                } catch (parseError) {
                    console.error('Invalid JSON response:', responseText);
                    this.formErrors = ['Server returned an invalid response. Please try again.'];
                    this.saving = false;
                    return;
                }
                
                if (!response.ok) {
                    // Handle error response
                    const errorMessages = data.errors || [data.message || 'Failed to save member'];
                    this.formErrors = Array.isArray(errorMessages) ? errorMessages : [errorMessages];
                    Toast.error(errorMessages[0] || 'Failed to save member');
                    return;
                }
                
                if (data.success) {
                    Toast.success(data.message || (this.memberForm.id ? 'Member updated successfully' : 'Member added successfully'));
                    this.showMemberModal = false;
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    const errorMessages = data.errors || [data.message || 'Failed to save member'];
                    this.formErrors = Array.isArray(errorMessages) ? errorMessages : [errorMessages];
                    Toast.error(errorMessages[0] || 'Failed to save member');
                }
            } catch (error) {
                console.error('Error saving member:', error);
                const errorMessage = error.message || 'An error occurred while saving';
                this.formErrors = [errorMessage];
                Toast.error(errorMessage);
            } finally {
                this.saving = false;
            }
        },
        
        async deleteMember(memberId, memberName) {
            const confirmed = await confirmAction({
                title: 'Delete Member',
                message: `Are you sure you want to delete ${memberName}? This action will permanently remove them from the database and cannot be undone.`,
                type: 'danger',
                okText: 'Permanently Delete',
                cancelText: 'Cancel'
            });
            
            if (!confirmed) return;
            
            try {
                const response = await fetch(`${API_BASE_URL}/members.php?id=${memberId}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        csrf_token: csrfToken
                    })
                });
                
                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({ message: 'Server error' }));
                    throw new Error(errorData.message || 'Failed to delete member');
                }
                
                const data = await response.json();
                
                if (data.success) {
                    Toast.success(data.message || 'Member deleted successfully');
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    Toast.error(data.message || 'Failed to delete member');
                }
            } catch (error) {
                console.error('Error deleting member:', error);
                Toast.error('An error occurred while deleting');
            }
        },
        
        async reactivateMember(memberId) {
            const confirmed = await confirmAction({
                title: 'Reactivate Member',
                message: 'Are you sure you want to reactivate this member?',
                type: 'info',
                okText: 'Reactivate',
                cancelText: 'Cancel'
            });
            
            if (!confirmed) return;
            
            this.saving = true;
            try {
                const response = await fetch(`${API_BASE_URL}/members.php?id=${memberId}`, {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        status: 'active',
                        csrf_token: csrfToken
                    })
                });
                
                const data = await response.json();
                if (data.success) {
                    Toast.success('Member reactivated successfully');
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    Toast.error(data.message || 'Failed to reactivate member');
                }
            } catch (error) {
                console.error('Error reactivating member:', error);
                Toast.error('An error occurred');
            } finally {
                this.saving = false;
            }
        },
        
        openBulkEmailModal() {
            if (this.selectedMembers.length === 0) {
                Toast.error('Please select members first');
                return;
            }
            this.bulkEmailForm = {
                template_id: '',
                subject: '',
                body: ''
            };
            this.previewSubject = '';
            this.previewBody = '';
            this.showBulkEmailModal = true;
        },

        async loadEmailTemplate() {
            if (!this.bulkEmailForm.template_id) {
                this.bulkEmailForm.subject = '';
                this.bulkEmailForm.body = '';
                this.generatePreview();
                this.$nextTick(() => setTimeout(() => this.initBulkEmailWysiwyg(), 30));
                return;
            }

            try {
                const response = await fetch(`${API_BASE_URL}/email-templates.php?action=get&id=${this.bulkEmailForm.template_id}`, {
                    headers: { 'X-CSRF-Token': csrfToken }
                });
                const data = await response.json();
                if (data.success) {
                    this.bulkEmailForm.subject = data.template.subject;
                    this.bulkEmailForm.body = data.template.body_html;
                    this.generatePreview();
                    this.$nextTick(() => setTimeout(() => this.initBulkEmailWysiwyg(), 30));
                }
            } catch (error) {
                console.error('Error loading template:', error);
                Toast.error('Failed to load template');
            }
        },

        generatePreview() {
            this.flushBulkEmailBodyFromEditor();
            let subject = this.bulkEmailForm.subject;
            let body = this.bulkEmailForm.body;

            // Simple preview by replacing tags with sample data
            const sampleData = {
                '{first_name}': 'John',
                '{last_name}': 'Doe',
                '{full_name}': 'John Doe',
                '{email}': 'john.doe@example.com',
                '{organization_name}': 'Your Organization'
            };

            for (const [tag, value] of Object.entries(sampleData)) {
                subject = subject.replace(new RegExp(tag, 'g'), value);
                body = body.replace(new RegExp(tag, 'g'), value);
            }

            this.previewSubject = subject;
            this.previewBody = body;
        },

        async sendBulkEmail() {
            this.flushBulkEmailBodyFromEditor();
            if (!this.bulkEmailForm.subject || !this.bulkEmailForm.body) {
                Toast.error('Please enter a subject and message');
                return;
            }

            this.sendingBulk = true;
            try {
                const response = await fetch(`${API_BASE_URL}/bulk-email.php`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        user_ids: this.selectedMembers,
                        template_id: this.bulkEmailForm.template_id,
                        subject: this.bulkEmailForm.subject,
                        body: this.bulkEmailForm.body,
                        csrf_token: csrfToken
                    })
                });

                const data = await response.json();
                if (data.success) {
                    Toast.success(data.message);
                    this.showBulkEmailModal = false;
                    this.selectedMembers = [];
                } else {
                    Toast.error(data.message || 'Failed to send emails');
                }
            } catch (error) {
                console.error('Error sending bulk email:', error);
                Toast.error('An error occurred while sending emails');
            } finally {
                this.sendingBulk = false;
            }
        },

        resetForm() {
            this.memberForm = {
                id: null,
                first_name: '',
                last_name: '',
                email: '',
                phone: '',
                gender: '',
                date_of_birth: ''
            };
            this.formErrors = [];
        }
    };
}

// Initialize and store app instance globally after Alpine loads
document.addEventListener('alpine:init', () => {
    // This will be set when the component initializes
});

// Handle per page change
function changePerPage(value) {
    const url = new URL(window.location.href);
    url.searchParams.set('per_page', value);
    url.searchParams.set('p', '1'); // Reset to first page when changing per page (use 'p' not 'page')
    // Preserve 'page' parameter for routing
    if (!url.searchParams.has('page')) {
        url.searchParams.set('page', 'members');
    }
    window.location.href = url.toString();
}

// Member Tags Management App
function memberTagsApp() {
    return {
        memberId: null,
        memberTags: [],
        availableTags: <?php $jat = json_encode($allTags); echo ($jat !== false) ? $jat : '[]'; ?>,
        loading: false,
        
        init() {
            // Listen for load event
            window.addEventListener('loadMemberTags', (e) => {
                this.memberId = e.detail.memberId;
                this.loadMemberTags();
            });
        },
        
        async loadMemberTags() {
            if (!this.memberId) return;
            
            this.loading = true;
            try {
                const response = await fetch(`${API_BASE_URL}/tags.php?action=member_tags&member_id=${this.memberId}`);
                const data = await response.json();
                
                if (data.success) {
                    this.memberTags = data.tags || [];
                } else {
                    Toast.error(data.message || 'Failed to load member tags');
                }
            } catch (error) {
                console.error('Error loading member tags:', error);
                Toast.error('Failed to load member tags');
            } finally {
                this.loading = false;
            }
        },
        
        isTagAssigned(tagId) {
            return this.memberTags.some(t => t.id === tagId);
        },
        
        async assignTag(tagId) {
            if (this.isTagAssigned(tagId)) return;
            
            try {
                const response = await fetch(`${API_BASE_URL}/tags.php?action=assign`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        member_id: this.memberId,
                        tag_id: tagId
                    })
                });
                
                const data = await response.json();
                if (data.success) {
                    // Reload member tags
                    await this.loadMemberTags();
                    Toast.success('Tag assigned successfully');
                    // Reload page to update the tags display
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    Toast.error(data.message || 'Failed to assign tag');
                }
            } catch (error) {
                console.error('Error assigning tag:', error);
                Toast.error('Failed to assign tag');
            }
        },
        
        async removeTag(tagId) {
            try {
                const response = await fetch(`${API_BASE_URL}/tags.php?action=remove`, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        member_id: this.memberId,
                        tag_id: tagId
                    })
                });
                
                const data = await response.json();
                if (data.success) {
                    // Reload member tags
                    await this.loadMemberTags();
                    Toast.success('Tag removed successfully');
                    // Reload page to update the tags display
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    Toast.error(data.message || 'Failed to remove tag');
                }
            } catch (error) {
                console.error('Error removing tag:', error);
                Toast.error('Failed to remove tag');
            }
        }
    };
}

// Also assign to window for compatibility
window.membersApp = membersApp;
window.memberTagsApp = memberTagsApp;

// Handle Import Members form submission
async function handleImportMembers(event) {
    event.preventDefault();
    const form = event.target;
    const errorsDiv = document.getElementById('import-member-errors');
    if (errorsDiv) {
        errorsDiv.textContent = '';
        errorsDiv.innerHTML = '';
    }
    
    const formData = new FormData(form);
    formData.append('csrf_token', csrfToken);
    
    const fileInput = form.querySelector('input[type="file"]');
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
        if (errorsDiv) {
            errorsDiv.textContent = 'Please select a CSV file to upload';
        }
        Toast.error('Please select a CSV file to upload');
        return;
    }
    
    try {
        // Use the import-members API endpoint
        const apiUrl = `${API_BASE_URL}/import-members.php`;
        const response = await fetch(apiUrl, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken
            },
            credentials: 'same-origin',
            body: formData
        });
        
        // Check content type
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            const errorText = await response.text();
            console.error('Non-JSON response:', errorText);
            throw new Error('Server returned an error. Please check the console for details.');
        }
        
        const result = await response.json();
        
        if (result.success) {
            const imported = result.imported || 0;
            const skipped = result.skipped || 0;
            const errors = result.errors || 0;
            
            let message = `Successfully imported ${imported} member${imported !== 1 ? 's' : ''}!`;
            if (skipped > 0) {
                message += ` ${skipped} duplicate${skipped !== 1 ? 's' : ''} skipped.`;
            }
            if (errors > 0) {
                message += ` ${errors} error${errors !== 1 ? 's' : ''} occurred.`;
            }
            
            Toast.success(message);
            
            // Close modal using Alpine.js
            if (window.membersAppInstance) {
                window.membersAppInstance.showImportModal = false;
            }
            form.reset();
            setTimeout(() => window.location.reload(), 1000);
        } else {
            const errorMsg = result.message || 'Failed to import members';
            if (errorsDiv) {
                errorsDiv.textContent = errorMsg;
            }
            if (result.errors && result.errors.length > 0) {
                if (errorsDiv) {
                    errorsDiv.innerHTML = '<div class="text-sm"><strong>Errors:</strong><br>' + 
                        result.errors.map(e => (typeof e === 'string' ? e : e.message || JSON.stringify(e))).join('<br>') + 
                        '</div>';
                }
            }
            Toast.error(errorMsg);
        }
    } catch (error) {
        console.error('Import error:', error);
        const errorMsg = error.message || 'An error occurred. Please check the console for details.';
        if (errorsDiv) {
            errorsDiv.textContent = errorMsg;
        }
        Toast.error('Failed to import members: ' + errorMsg);
    }
}
</script>

<style>
    [x-cloak] { display: none !important; }
    #bulk-email-body-wrap .ql-editor { min-height: 220px; font-size: 14px; }
    #bulk-email-body-wrap .ql-toolbar.ql-snow { border-radius: 0.75rem 0.75rem 0 0; }
    #bulk-email-body-wrap .ql-container.ql-snow { border-radius: 0 0 0.75rem 0.75rem; }
</style>
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script src="<?= e($basePath) ?>/public/admin/js/quill-rich-toolbar.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>
