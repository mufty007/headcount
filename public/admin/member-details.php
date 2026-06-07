<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../src/helpers.php';

use Headcount\Services\MemberService;
use Headcount\Middleware\AuthMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Utilities;

AuthMiddleware::requireAdmin();

// Calculate base path if not set (from index.php)
if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}

// Get and validate member ID
$memberId = isset($_GET['id']) ? trim($_GET['id']) : null;

// Validate member ID
if (!$memberId || !is_numeric($memberId) || (int)$memberId <= 0) {
    Utilities::redirect($adminBase . '/?page=members');
    exit;
}

$memberId = (int)$memberId;

$memberService = new MemberService();
$organizationId = AuthMiddleware::getOrganizationId();

try {
    $member = $memberService->getMember($memberId);
    
    // Check if member exists
    if (!$member) {
        Utilities::redirect($adminBase . '/?page=members');
        exit;
    }
    
    // Security check: ensure member belongs to this organization
    if ($member['organization_id'] != $organizationId) {
        http_response_code(403);
        die("Unauthorized access");
    }
    
    $stats = $memberService->getMemberStats($memberId);
    
    // Get tags and groups for display (with error handling in case tables don't exist)
    $db = Database::getInstance();
    $tags = [];
    $groups = [];
    
    try {
        // Check if tags table exists
        $tagsTableCheck = $db->query("SHOW TABLES LIKE 'tags'");
        if (!empty($tagsTableCheck)) {
            $tags = $db->query("
                SELECT t.* FROM tags t 
                JOIN member_tags mt ON t.id = mt.tag_id 
                WHERE mt.user_id = :user_id
            ", ['user_id' => $memberId]);
        }
    } catch (\Exception $e) {
        error_log("Tags query failed: " . $e->getMessage());
        $tags = [];
    }
    
    try {
        // Check if groups table exists
        $groupsTableCheck = $db->query("SHOW TABLES LIKE 'member_groups'");
        if (!empty($groupsTableCheck)) {
            $groups = $db->query("
                SELECT mg.* FROM member_groups mg 
                JOIN group_members gm ON mg.id = gm.group_id 
                WHERE gm.user_id = :user_id
            ", ['user_id' => $memberId]);
        }
    } catch (\Exception $e) {
        error_log("Groups query failed: " . $e->getMessage());
        $groups = [];
    }
    
} catch (Exception $e) {
    error_log("Member details error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    
    // Redirect to members page with error message instead of showing error
    $_SESSION['error_message'] = 'Failed to load member details. Please try again.';
    Utilities::redirect($adminBase . '/?page=members');
    exit;
}

$pageTitle = $member['first_name'] . ' ' . $member['last_name'] . ' - Member Details';
$currentPage = 'members';

// Calculate asset paths (if not already set)
if (!isset($cssBase)) {
    $cssBase = $basePath . '/public/css/';
}
if (!isset($jsBase)) {
    $jsBase = $basePath . '/public/js/';
}
if (!isset($apiBase)) {
    $apiBase = ($basePath ? rtrim($basePath, '/') : '') . '/api';
}

// Generate CSRF token
use Headcount\Middleware\CsrfMiddleware;
$csrfToken = CsrfMiddleware::getToken();

// Add modal.css for confirm dialogs
if (!isset($additionalCSS)) {
    $additionalCSS = [];
}
$additionalCSS[] = $cssBase . 'modal.css';

require __DIR__ . '/includes/header.php';
?>

<div class="content-wrapper" x-data="memberDetailApp(<?= $memberId ?>)">
    <?php
    $pageHeaderBreadcrumb = [
        ['label' => 'Dashboard', 'url' => $adminBase . '/?page=dashboard'],
        ['label' => 'Members', 'url' => $adminBase . '/?page=members'],
        ['label' => $member['first_name'] . ' ' . $member['last_name']],
    ];
    $pageHeaderTitle = $member['first_name'] . ' ' . $member['last_name'];
    $pageHeaderSubtitle = 'Member profile and activity';
    ob_start();
    ?>
            <button @click="openEditModal()" :disabled="loading" class="btn-secondary flex items-center gap-2">
                <svg x-show="!loading" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                Edit Profile
            </button>
            <template x-if="memberData && memberData.status !== 'deleted'">
                <button @click="deleteMember()" :disabled="saving" class="btn-secondary text-error-600 border-error-200 hover:bg-error-600 hover:text-white">Delete</button>
            </template>
    <?php
    $pageHeaderActions = ob_get_clean();
    require __DIR__ . '/components/page-header.php';
    ?>

    <!-- Profile header -->
    <div class="mb-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <div class="flex flex-col gap-6 md:flex-row md:items-center md:justify-between">
            <div class="flex items-center gap-6">
                <div class="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl bg-brand-600 text-3xl font-black text-white shadow-xl shadow-brand-200" x-show="memberData">
                    <span x-text="memberData ? (memberData.first_name.charAt(0) + memberData.last_name.charAt(0)).toUpperCase() : ''"></span>
                </div>
                <div x-show="loading" class="flex h-20 w-20 shrink-0 items-center justify-center rounded-2xl bg-gray-100">
                    <svg class="h-8 w-8 animate-spin text-gray-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                </div>
                <div>
                    <h2 class="mb-2 text-2xl font-black text-gray-900 dark:text-white" x-text="memberData ? (memberData.first_name + ' ' + memberData.last_name) : 'Loading...'"></h2>
                    <div class="flex flex-wrap gap-2" x-show="memberData">
                        <span class="status-badge" :class="memberData && memberData.status === 'active' ? 'bg-emerald-50 text-emerald-700 border-emerald-100' : 'bg-gray-50 text-gray-700 border-gray-100'">
                            <span x-text="memberData ? memberData.status.charAt(0).toUpperCase() + memberData.status.slice(1) : ''"></span>
                        </span>
                        <span class="status-badge bg-brand-50 text-brand-700 border-brand-100">ID: #<span x-text="memberData ? memberData.id : ''"></span></span>
                        <template x-if="memberData && memberData.gender">
                            <span class="status-badge border-violet-100 bg-violet-50 text-violet-800 uppercase tracking-widest text-[10px]" x-text="memberData.gender"></span>
                        </template>
                    </div>
                </div>
            </div>
            <div class="flex flex-wrap gap-3">
                <template x-if="memberData && memberData.email">
                    <button type="button" @click="generateCredentials()" :disabled="saving" class="btn-secondary flex items-center gap-2 border-brand-100 bg-brand-50 text-brand-700 hover:bg-brand-600 hover:text-white">
                        Generate Credentials
                    </button>
                </template>
                <button @click="refreshData()" :disabled="loading" class="btn-secondary flex items-center gap-2" title="Refresh">Refresh</button>
                <template x-if="memberData && memberData.status === 'deleted'">
                    <button @click="reactivateMember()" :disabled="saving" class="btn-primary bg-success-600 hover:bg-success-700">Reactivate</button>
                </template>
            </div>
        </div>
    </div>

    <div class="mb-6">
        <?php
        $cardTabs = [
            ['id' => 'overview', 'label' => 'Overview', 'active' => true],
            ['id' => 'attendance', 'label' => 'Attendance'],
            ['id' => 'rsvps', 'label' => 'RSVPs & No-shows'],
        ];
        $cardTabsVar = 'activeTab';
        $cardTabsParentScope = true;
        require __DIR__ . '/components/card-tabs.php';
        unset($cardTabs, $cardTabsVar, $cardTabsParentScope);
        ?>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <!-- Sidebar Info -->
        <div class="space-y-8">
            <!-- Contact Box -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-6">Contact Information</h3>
                <div class="space-y-4">
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-gray-50 flex items-center justify-center text-gray-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                        </div>
                        <div>
                            <div class="text-xs font-bold text-gray-400 uppercase tracking-tighter">Email</div>
                            <div class="text-sm font-bold text-gray-900" x-text="memberData ? memberData.email : ''"></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-gray-50 flex items-center justify-center text-gray-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                        </div>
                        <div>
                            <div class="text-xs font-bold text-gray-400 uppercase tracking-tighter">Phone</div>
                            <div class="text-sm font-bold text-gray-900" x-text="memberData && memberData.phone ? memberData.phone : 'Not set'"></div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="w-10 h-10 rounded-xl bg-gray-50 flex items-center justify-center text-gray-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                        </div>
                        <div>
                            <div class="text-xs font-bold text-gray-400 uppercase tracking-tighter">Member Since</div>
                            <div class="text-sm font-bold text-gray-900" x-text="memberData ? formatDate(memberData.created_at) : ''"></div>
                        </div>
                    </div>
                    <template x-if="memberData && memberData.date_of_birth">
                        <div class="flex items-center gap-4">
                            <div class="w-10 h-10 rounded-xl bg-gray-50 flex items-center justify-center text-gray-400">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            </div>
                            <div>
                                <div class="text-xs font-bold text-gray-400 uppercase tracking-tighter">Date of Birth</div>
                                <div class="text-sm font-bold text-gray-900" x-text="formatDate(memberData.date_of_birth)"></div>
                                <div class="text-[10px] text-gray-500 mt-0.5" x-text="calculateAge(memberData.date_of_birth)"></div>
                            </div>
                        </div>
                    </template>
                    <div class="flex items-center gap-4 pt-2 border-t border-gray-100">
                        <div class="w-10 h-10 rounded-xl bg-gray-50 flex items-center justify-center text-gray-400">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                        </div>
                        <div class="flex-1">
                            <div class="text-xs font-bold text-gray-400 uppercase tracking-tighter mb-1">Email Status</div>
                            <span class="status-badge text-[10px]" :class="statsData ? statsData.email_status_class : 'bg-gray-50 text-gray-700 border-gray-100'" x-text="statsData ? statsData.email_status_text : 'Loading...'"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-6">Quick Actions</h3>
                <div class="space-y-3">
                    <button 
                        @click="openEditModal()" 
                        :disabled="loading || saving"
                        class="w-full btn-secondary text-brand-600 border-brand-100 hover:bg-brand-50 flex items-center justify-center gap-2 text-sm"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
                        Edit Profile
                    </button>
                    <template x-if="memberData && memberData.email">
                        <button 
                            @click="generateCredentials()" 
                            :disabled="saving"
                            class="btn-secondary flex w-full items-center justify-center gap-2 border-brand-100 bg-brand-50 text-sm text-brand-700 hover:bg-brand-100"
                        >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path></svg>
                            Generate Credentials
                        </button>
                    </template>
                    <a 
                        :href="adminBase + '/?page=activity-log&user_id=' + memberId"
                        class="w-full btn-secondary flex items-center justify-center gap-2 text-sm"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                        View Activity Log
                    </a>
                </div>
            </div>

            <!-- Linked Family -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-6">Family Members</h3>
                <div class="space-y-3" x-show="familyData && familyData.length > 0">
                    <template x-for="(family, index) in (familyData || [])" :key="family.id ? `rel-${family.id}` : `rel-${family.related_member_id}-${index}`">
                        <div>
                            <!-- Linked family member (has user account) - clickable -->
                            <a 
                                x-show="family && family.is_linked !== false && !String(family.related_member_id).startsWith('unlinked-')"
                                :href="adminBase + '/?page=member-details&id=' + family.related_member_id"
                                class="flex items-center gap-3 p-3 rounded-xl border border-gray-100 hover:border-brand-200 hover:bg-brand-50 transition-all group"
                            >
                                <div class="w-10 h-10 rounded-full bg-brand-100 flex items-center justify-center text-brand-600 font-bold text-sm group-hover:bg-brand-600 group-hover:text-white transition-all">
                                    <span x-text="(family.related_first_name ? family.related_first_name.charAt(0) : '') + (family.related_last_name ? family.related_last_name.charAt(0) : '')"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="font-bold text-gray-900 text-sm group-hover:text-brand-600 transition-colors" x-text="(family.related_first_name || '') + ' ' + (family.related_last_name || '')"></div>
                                    <div class="text-xs text-gray-500 mt-0.5" x-text="formatRelationshipType(family.relationship_type)"></div>
                                </div>
                                <svg class="w-4 h-4 text-gray-400 group-hover:text-brand-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                            </a>
                            
                            <!-- Unlinked family member (no user account) - non-clickable -->
                            <div 
                                x-show="family && (family.is_linked === false || String(family.related_member_id).startsWith('unlinked-'))"
                                class="flex items-center gap-3 p-3 rounded-xl border border-gray-100 bg-gray-50"
                            >
                                <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center text-gray-500 font-bold text-sm">
                                    <span x-text="(family.related_first_name ? family.related_first_name.charAt(0) : '') + (family.related_last_name ? family.related_last_name.charAt(0) : '')"></span>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="font-bold text-gray-700 text-sm" x-text="(family.related_first_name || '') + ' ' + (family.related_last_name || '')"></div>
                                    <div class="text-xs text-gray-500 mt-0.5" x-text="formatRelationshipType(family.relationship_type)"></div>
                                    <div class="text-[10px] text-gray-400 mt-1 italic">No user account</div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                <div x-show="!familyData || familyData.length === 0" class="text-center py-6">
                    <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path></svg>
                    <p class="text-xs text-gray-400 italic">No family members added</p>
                </div>
            </div>

            <!-- Tags & Groups -->
            <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
                <h3 class="text-xs font-black text-gray-400 uppercase tracking-widest mb-6">Tags & Groups</h3>
                <div class="space-y-6">
                    <div>
                        <div class="text-[10px] font-black text-gray-400 uppercase tracking-tighter mb-3">Assigned Tags</div>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="tag in tagsData" :key="tag.id">
                                <span class="status-badge bg-brand-50 text-brand-700 border-brand-100 flex items-center gap-2">
                                    <div class="w-1.5 h-1.5 rounded-full" :style="'background-color: ' + tag.color"></div>
                                    <span x-text="tag.name"></span>
                                </span>
                            </template>
                            <template x-if="!tagsData || tagsData.length === 0">
                                <span class="text-xs text-gray-400 italic">No tags assigned</span>
                            </template>
                        </div>
                    </div>
                    <div>
                        <div class="text-[10px] font-black text-gray-400 uppercase tracking-tighter mb-3">Group Memberships</div>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="group in groupsData" :key="group.id">
                                <span class="status-badge bg-emerald-50 text-emerald-700 border-emerald-100 flex items-center gap-2">
                                    <div class="w-1.5 h-1.5 rounded-full" :style="'background-color: ' + group.color"></div>
                                    <span x-text="group.name"></span>
                                    <button 
                                        @click="removeGroup(group.id)"
                                        class="ml-1 text-emerald-400 hover:text-emerald-600 transition-colors"
                                        title="Remove from group"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                    </button>
                                </span>
                            </template>
                            <template x-if="!groupsData || groupsData.length === 0">
                                <span class="text-xs text-gray-400 italic">No group memberships</span>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Stats & Activity -->
        <div class="lg:col-span-2 space-y-8">
            <!-- Stats Grid (Overview tab) -->
            <div x-show="activeTab === 'overview' && statsData" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="rounded-2xl border border-gray-200 bg-brand-600 p-5 text-white shadow-theme-sm md:p-6">
                    <div class="text-[10px] font-black uppercase tracking-widest opacity-60 mb-1">Events Attended</div>
                    <div class="text-4xl font-black mb-1" x-text="statsData ? statsData.total_attended : 0"></div>
                    <div class="text-[10px] font-bold opacity-60">Lifetime check-ins</div>
                </div>
                <div class="rounded-2xl border border-emerald-100 bg-emerald-50 p-5 shadow-theme-sm md:p-6">
                    <div class="text-[10px] font-black text-emerald-600 uppercase tracking-widest mb-1">Events Registered</div>
                    <div class="text-4xl font-black text-emerald-900 mb-1" x-text="statsData ? statsData.total_signed_up : 0"></div>
                    <div class="text-[10px] font-bold text-emerald-600">RSVP'd Yes</div>
                </div>
                <div class="rounded-2xl border border-amber-100 bg-amber-50 p-5 shadow-theme-sm md:p-6">
                    <div class="text-[10px] font-black text-amber-600 uppercase tracking-widest mb-1">No-Shows</div>
                    <div class="text-4xl font-black text-amber-900 mb-1" x-text="statsData ? statsData.no_shows : 0"></div>
                    <div class="text-[10px] font-bold text-amber-600">Didn't attend</div>
                </div>
                <div class="rounded-2xl border border-gray-200 p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-6" :class="statsData ? statsData.email_status_class : 'bg-gray-50'">
                    <div class="text-[10px] font-black text-gray-600 uppercase tracking-widest mb-1">Email Status</div>
                    <div class="text-sm font-black text-gray-900 mb-1 leading-tight" x-text="statsData ? statsData.email_status_text : 'Loading...'"></div>
                    <div class="text-[10px] font-bold text-gray-500 mt-2">Communication</div>
                </div>
            </div>

            <!-- Secondary Stats -->
            <div x-show="activeTab === 'overview' && statsData" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Attendance Rate</div>
                    <div class="text-3xl font-black text-gray-900 mb-1" x-text="statsData ? (statsData.attendance_rate + '%') : '0%'"></div>
                    <div class="text-[10px] font-bold text-gray-500">Based on sign-ups</div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Last Attendance</div>
                    <div class="text-sm font-black text-gray-900 mb-1">
                        <template x-if="statsData && statsData.last_attendance">
                            <span x-text="formatDate(statsData.last_attendance.event_date)"></span>
                        </template>
                        <template x-if="!statsData || !statsData.last_attendance">
                            <span class="text-gray-400 italic">Never</span>
                        </template>
                    </div>
                    <div class="text-[10px] font-bold text-gray-500">
                        <template x-if="statsData && statsData.last_attendance">
                            <span x-text="statsData.last_attendance.event_title"></span>
                        </template>
                        <template x-if="!statsData || !statsData.last_attendance">
                            <span>No events attended</span>
                        </template>
                    </div>
                </div>
                <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] md:p-6">
                    <div class="text-[10px] font-black text-gray-400 uppercase tracking-widest mb-1">Last RSVP</div>
                    <div class="text-sm font-black text-gray-900 mb-1">
                        <template x-if="statsData && statsData.last_rsvp">
                            <span x-text="formatDate(statsData.last_rsvp.created_at)"></span>
                        </template>
                        <template x-if="!statsData || !statsData.last_rsvp">
                            <span class="text-gray-400 italic">Never</span>
                        </template>
                    </div>
                    <div class="text-[10px] font-bold text-gray-500">
                        <template x-if="statsData && statsData.last_rsvp">
                            <span x-text="statsData.last_rsvp.event_title"></span>
                        </template>
                        <template x-if="!statsData || !statsData.last_rsvp">
                            <span>No RSVPs yet</span>
                        </template>
                    </div>
                </div>
            </div>

            <!-- Recent Activity -->
            <div x-show="activeTab === 'attendance'" class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:px-6">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Recent Attendance History</h3>
                </div>
                <div class="w-full overflow-x-auto custom-scrollbar">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-y border-gray-100 dark:border-gray-800">
                                <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Event Name</p></th>
                                <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Date</p></th>
                                <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Time</p></th>
                                <th class="py-3 pr-4 text-right"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</p></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <template x-for="att in (statsData ? statsData.recent_attendance : [])" :key="att.id">
                                <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                    <td class="py-3 pr-4 text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="att.event_title"></td>
                                    <td class="py-3 pr-4 text-theme-sm text-gray-500 dark:text-gray-400" x-text="formatDate(att.event_date)"></td>
                                    <td class="py-3 pr-4 text-theme-sm text-gray-400" x-text="att.start_time ? formatTime(att.start_time) : ''"></td>
                                    <td class="py-3 pr-4 text-right">
                                        <span class="inline-flex rounded-full bg-success-50 px-2.5 py-0.5 text-theme-xs font-medium text-success-600 dark:bg-success-500/15 dark:text-success-400">Checked In</span>
                                    </td>
                                </tr>
                            </template>
                            <template x-if="!statsData || !statsData.recent_attendance || statsData.recent_attendance.length === 0">
                                <tr>
                                    <td colspan="4" class="p-12 text-center">
                                        <p class="text-gray-400 italic text-sm">No attendance records found.</p>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent RSVPs -->
            <div x-show="activeTab === 'rsvps'" class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:px-6">
                <div class="mb-4">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">Recent RSVP Decisions</h3>
                </div>
                <div class="w-full overflow-x-auto custom-scrollbar">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-y border-gray-100 dark:border-gray-800">
                                <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Event Name</p></th>
                                <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Event Date</p></th>
                                <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Response</p></th>
                                <th class="py-3 pr-4 text-right"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Response Date</p></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <template x-for="rsvp in (statsData ? statsData.recent_rsvps : [])" :key="rsvp.id">
                                <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                    <td class="py-3 pr-4 text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="rsvp.event_title"></td>
                                    <td class="py-3 pr-4 text-theme-sm text-gray-500 dark:text-gray-400" x-text="formatDate(rsvp.event_date)"></td>
                                    <td class="py-3 pr-4">
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-theme-xs font-medium"
                                              :class="rsvp.status === 'yes' ? 'bg-success-50 text-success-600 dark:bg-success-500/15 dark:text-success-400' : rsvp.status === 'no' ? 'bg-error-50 text-error-600 dark:bg-error-500/15 dark:text-error-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300'"
                                              x-text="rsvp.status === 'yes' ? 'Yes' : rsvp.status === 'no' ? 'No' : 'Maybe'"></span>
                                    </td>
                                    <td class="py-3 pr-4 text-right text-theme-sm text-gray-400" x-text="formatDateTime(rsvp.created_at)"></td>
                                </tr>
                            </template>
                            <template x-if="!statsData || !statsData.recent_rsvps || statsData.recent_rsvps.length === 0">
                                <tr>
                                    <td colspan="4" class="p-12 text-center">
                                        <p class="text-gray-400 italic text-sm">No RSVP records found.</p>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- No-Show Events -->
            <div x-show="activeTab === 'rsvps' && statsData && statsData.no_show_events && statsData.no_show_events.length > 0" class="overflow-hidden rounded-2xl border border-gray-200 bg-white px-4 pb-3 pt-4 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03] sm:px-6">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-white/90">No-Show Events</h3>
                    <span class="inline-flex rounded-full bg-warning-50 px-2.5 py-0.5 text-theme-xs font-medium text-warning-600">
                        <span x-text="statsData ? statsData.no_show_events.length : 0"></span>
                        <span x-text="statsData && statsData.no_show_events.length !== 1 ? ' events' : ' event'"></span>
                    </span>
                </div>
                <div class="w-full overflow-x-auto custom-scrollbar">
                    <table class="min-w-full">
                        <thead>
                            <tr class="border-y border-gray-100 dark:border-gray-800">
                                <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Event Name</p></th>
                                <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Event Date</p></th>
                                <th class="py-3 pr-4 text-left"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">RSVP Date</p></th>
                                <th class="py-3 pr-4 text-right"><p class="text-theme-xs font-medium text-gray-500 dark:text-gray-400">Status</p></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <template x-for="event in (statsData ? statsData.no_show_events : [])" :key="event.id">
                                <tr class="transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.02]">
                                    <td class="py-3 pr-4 text-theme-sm font-medium text-gray-800 dark:text-white/90" x-text="event.event_title"></td>
                                    <td class="py-3 pr-4 text-theme-sm text-gray-500 dark:text-gray-400" x-text="formatDate(event.event_date)"></td>
                                    <td class="py-3 pr-4 text-theme-sm text-gray-400" x-text="formatDate(event.rsvp_date)"></td>
                                    <td class="py-3 pr-4 text-right">
                                        <span class="inline-flex rounded-full bg-warning-50 px-2.5 py-0.5 text-theme-xs font-medium text-warning-600 dark:bg-warning-500/15 dark:text-warning-400">No-Show</span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Credentials Modal -->
    <template x-if="typeof showCredentialsModal !== 'undefined'">
        <div x-show="showCredentialsModal" 
             x-cloak
             @keydown.escape.window="showCredentialsModal = false"
             class="fixed inset-0 flex items-start justify-center pt-8"
             style="display: none; z-index: 20000 !important;">
            
            <!-- Backdrop -->
            <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px] transition-opacity"
                 style="z-index: 20000 !important;"
                 @click="showCredentialsModal = false"></div>
            
            <!-- Modal Container - Top Centered -->
            <div class="relative mx-4 mt-8 w-full max-w-lg rounded-2xl border border-gray-200 bg-white shadow-card-lg"
                 style="z-index: 20001 !important; position: relative;"
                 @click.away="showCredentialsModal = false">
                
                <!-- Header -->
                <div class="flex items-center justify-between border-b border-gray-200 p-6">
                    <div class="flex items-center gap-3">
                        <div class="flex h-10 w-10 items-center justify-center rounded-full bg-brand-100">
                            <svg class="h-6 w-6 text-brand-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                            </svg>
                        </div>
                        <h3 class="text-xl font-extrabold text-gray-900">Credentials Generated</h3>
                    </div>
                    <button type="button" @click="showCredentialsModal = false" 
                            class="rounded-lg p-2 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-700" aria-label="Close">
                        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                
                <!-- Content -->
                <div class="p-6 space-y-4">
                    <p class="text-sm text-gray-600">Login credentials have been generated. Please copy and share these securely with the member.</p>
                    
                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-400 uppercase tracking-tighter mb-2">Email</label>
                            <div class="flex items-center gap-2">
                                <div class="flex-1 p-3 bg-gray-50 rounded-xl font-mono text-sm text-gray-900 break-all" x-text="credentialsData && credentialsData.email ? credentialsData.email : ''"></div>
                                <button 
                                    @click="copyToClipboard(credentialsData && credentialsData.email ? credentialsData.email : '')"
                                    class="p-2 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition-colors"
                                    title="Copy email">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-xs font-bold text-gray-400 uppercase tracking-tighter mb-2">Password</label>
                            <div class="flex items-center gap-2">
                                <div class="flex-1 break-all rounded-xl border border-brand-100 bg-brand-50/80 p-3 font-mono text-sm font-bold text-brand-950" x-text="credentialsData && credentialsData.password ? credentialsData.password : ''"></div>
                                <button 
                                    @click="copyToClipboard(credentialsData && credentialsData.password ? credentialsData.password : '')"
                                    class="rounded-lg p-2 text-brand-500 transition-colors hover:bg-brand-50 hover:text-brand-700"
                                    title="Copy password">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="pt-4 border-t border-gray-100">
                        <button 
                            @click="copyAllCredentials()"
                            class="w-full btn-primary flex items-center justify-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                            </svg>
                            Copy All to Clipboard
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <!-- Edit Member Modal -->
    <template x-if="typeof showEditModal !== 'undefined'">
        <div x-show="showEditModal" 
             x-cloak
             @keydown.escape.window="showEditModal = false"
             class="fixed inset-0 flex items-center justify-center p-4 overflow-y-auto"
             style="display: none; z-index: 15000 !important;">
            
            <!-- Backdrop -->
            <div class="fixed inset-0 bg-gray-900/55 backdrop-blur-[1px] transition-opacity"
                 style="z-index: 15000 !important;"
                 @click="showEditModal = false"></div>
            
            <!-- Modal -->
            <div class="relative w-full max-w-2xl transform rounded-2xl border border-gray-200 bg-white shadow-card-lg transition-all"
                 style="z-index: 15001 !important; position: relative;"
                     @click.away="showEditModal = false">
                
                <!-- Header -->
                <div class="flex items-center justify-between p-6 border-b border-gray-200">
                    <h3 class="text-xl font-extrabold text-gray-900">Edit Member</h3>
                    <button @click="showEditModal = false" 
                            class="text-gray-400 hover:text-gray-600 transition-colors">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    </button>
                </div>
                
                <!-- Content -->
                <div class="p-6">
                    <form @submit.prevent="saveMember()" class="space-y-6">
                        <!-- Error Messages -->
                        <div x-show="formErrors && formErrors.length > 0" x-transition class="bg-rose-50 border border-rose-100 text-rose-700 px-4 py-3 rounded-xl">
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
                                    class="w-full border border-gray-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all font-medium"
                                    required
                                >
                            </div>
                            
                            <div>
                                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Last Name</label>
                                <input 
                                    type="text" 
                                    x-model="memberForm.last_name"
                                    placeholder="e.g. Doe"
                                    class="w-full border border-gray-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all font-medium"
                                    required
                                >
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Email Address</label>
                            <input 
                                type="email" 
                                x-model="memberForm.email"
                                placeholder="john@example.com"
                                class="w-full border border-gray-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all font-medium"
                                required
                            >
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Phone Number</label>
                                <input 
                                    type="tel" 
                                    x-model="memberForm.phone"
                                    placeholder="(555) 000-0000"
                                    class="w-full border border-gray-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all font-medium"
                                >
                            </div>
                            
                            <div>
                                <label class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-2">Gender</label>
                                <select 
                                    x-model="memberForm.gender"
                                    class="w-full border border-gray-200 rounded-xl px-4 py-3 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all font-medium"
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
                                    class="w-full border border-gray-200 rounded-xl pl-10 pr-4 py-3 focus:ring-2 focus:ring-brand-500/20 focus:border-brand-500 outline-none transition-all font-medium"
                                >
                            </div>
                            <p class="mt-1 text-xs text-gray-500">Optional - Used for age calculations and demographics</p>
                        </div>
                        
                        <div class="flex items-center justify-end gap-3 pt-6 border-t border-gray-100">
                            <button 
                                type="button"
                                @click="showEditModal = false"
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
                                    <span x-text="saving ? 'Saving...' : 'Save Changes'"></span>
                                </div>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>

<script src="<?php echo htmlspecialchars($jsBase); ?>api.js?v=<?php echo time(); ?>"></script>
<script src="<?php echo htmlspecialchars($jsBase); ?>toast.js?v=<?php echo time(); ?>"></script>

<style>
    /* Ensure confirm dialog modal is visible */
    #confirm-dialog-modal {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 100% !important;
        z-index: 20000 !important;
        display: none;
        align-items: center;
        justify-content: center;
    }
    #confirm-dialog-modal.active {
        display: flex !important;
    }
    #confirm-dialog-modal .modal-overlay {
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        width: 100% !important;
        height: 100% !important;
        background-color: rgba(0, 0, 0, 0.5) !important;
        z-index: 1 !important;
    }
    #confirm-dialog-modal .modal-content {
        position: relative !important;
        z-index: 2 !important;
        background: white !important;
        border-radius: 0.75rem !important;
        padding: 2rem !important;
        max-width: 500px !important;
        width: 90% !important;
        box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1) !important;
    }
</style>
<script>
// API base URLs - use consistent path construction
const apiBaseUrl = '<?php echo htmlspecialchars($apiBase); ?>';
const API_BASE_URL = '<?= e($apiBase) ?>';
const csrfToken = '<?php echo htmlspecialchars($csrfToken); ?>';
const memberId = <?= $memberId ?>;
const adminBase = '<?= e($adminBase) ?>';

// Initial data from server (for first load)
const initialData = {
    member: <?= json_encode($member) ?>,
    stats: <?= json_encode($stats) ?>,
    tags: <?= json_encode($tags) ?>,
    groups: <?= json_encode($groups) ?>,
    family: []
};

function memberDetailApp(memberId) {
    return {
        memberId: memberId,
        activeTab: 'overview',
        loading: false,
        saving: false,
        memberData: initialData.member,
        statsData: initialData.stats,
        tagsData: initialData.tags,
        groupsData: initialData.groups,
        familyData: initialData.family,
        showEditModal: false,
        showCredentialsModal: false,
        credentialsData: null,
        memberForm: {
            id: null,
            first_name: '',
            last_name: '',
            email: '',
            phone: '',
            gender: '',
            date_of_birth: ''
        },
        formErrors: [],

        async init() {
            // Load data on init (will use cached initial data, but can refresh)
            // await this.loadData();
            await this.loadFamilyData();
        },

        async loadData() {
            this.loading = true;
            try {
                const response = await fetch(`${API_BASE_URL}/members.php?action=stats&id=${this.memberId}`, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken
                    },
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    throw new Error('Failed to load member data');
                }

                const data = await response.json();
                if (data.success && data.data) {
                    this.memberData = data.data.member;
                    this.statsData = data.data.stats;
                    this.tagsData = data.data.tags;
                    this.groupsData = data.data.groups;
                } else {
                    Toast.error(data.message || 'Failed to load member data');
                }
            } catch (error) {
                console.error('Error loading data:', error);
                Toast.error('Failed to load member data');
            } finally {
                this.loading = false;
            }
        },

        async loadFamilyData() {
            try {
                const url = `${API_BASE_URL}/members/${this.memberId}/relationships`;
                console.log('Loading family data from:', url);
                
                const response = await fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken,
                        'Accept': 'application/json'
                    },
                    credentials: 'same-origin'
                });

                if (!response.ok) {
                    console.log('Family data response not OK:', response.status, response.statusText);
                    // Try to get error message
                    try {
                        const errorData = await response.text();
                        console.error('Family API error response:', errorData.substring(0, 500));
                    } catch (e) {
                        console.error('Could not read error response');
                    }
                    this.familyData = [];
                    return;
                }

                const data = await response.json();
                console.log('Family data response:', data);
                
                if (data.success && data.data) {
                    // Ensure data.data is an array before filtering
                    const relationships = Array.isArray(data.data) ? data.data : [];
                    console.log('Family relationships found:', relationships.length);
                    // Filter out any invalid entries and ensure we have valid data
                    this.familyData = relationships.filter(f => f && f.related_member_id);
                    console.log('Filtered family data:', this.familyData.length);
                } else {
                    console.log('No family data in response:', data);
                    this.familyData = [];
                }
            } catch (error) {
                console.error('Error loading family data:', error);
                console.error('Error details:', error.message, error.stack);
                // Don't show error toast for family data, just set empty array
                this.familyData = [];
            }
        },

        async refreshData() {
            await this.loadData();
            await this.loadFamilyData();
            Toast.success('Data refreshed');
        },

        openEditModal() {
            if (!this.memberData) return;
            this.memberForm = {
                id: this.memberData.id,
                first_name: this.memberData.first_name,
                last_name: this.memberData.last_name,
                email: this.memberData.email,
                phone: this.memberData.phone || '',
                gender: this.memberData.gender || '',
                date_of_birth: this.memberData.date_of_birth || ''
            };
            this.formErrors = [];
            this.showEditModal = true;
        },

        async saveMember() {
            this.formErrors = [];
            this.saving = true;
            
            try {
                const url = `${apiBaseUrl}/members/${this.memberForm.id}`;
                const response = await fetch(url, {
                    method: 'PUT',
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
                
                if (!response.ok) {
                    const errorData = await response.json().catch(() => ({ message: 'Server error' }));
                    throw new Error(errorData.message || 'Failed to save member');
                }
                
                const data = await response.json();
                
                if (data.success) {
                    Toast.success(data.message || 'Member updated successfully');
                    this.showEditModal = false;
                    await this.loadData(); // Refresh data
                } else {
                    this.formErrors = data.errors || [data.message || 'Failed to save member'];
                }
            } catch (error) {
                console.error('Error saving member:', error);
                this.formErrors = [error.message || 'An error occurred while saving'];
            } finally {
                this.saving = false;
            }
        },

        async deleteMember() {
            if (!this.memberData) return;
            
            console.log('deleteMember called for member:', this.memberId);
            
            const confirmed = await confirmAction({
                title: 'Delete Member',
                message: `Are you sure you want to delete ${this.memberData.first_name} ${this.memberData.last_name}? This action cannot be undone.`,
                type: 'danger',
                okText: 'Delete',
                cancelText: 'Cancel'
            });
            
            console.log('Delete confirmation result:', confirmed);
            
            if (!confirmed) {
                console.log('User cancelled delete');
                return;
            }
            
            // Small delay to ensure confirm dialog is fully closed
            await new Promise(resolve => setTimeout(resolve, 100));
            
            this.saving = true;
            try {
                const url = `${apiBaseUrl}/members/${this.memberId}`;
                console.log('Deleting member via URL:', url);
                console.log('API Base URL:', apiBaseUrl);
                console.log('Member ID:', this.memberId);
                
                const response = await fetch(url, {
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
                
                console.log('Delete response status:', response.status, response.statusText);
                
                if (!response.ok) {
                    const errorText = await response.text();
                    console.error('Delete error response:', errorText);
                    let errorData;
                    try {
                        errorData = JSON.parse(errorText);
                    } catch (e) {
                        errorData = { message: errorText || 'Server error' };
                    }
                    throw new Error(errorData.message || 'Failed to delete member');
                }
                
                const data = await response.json();
                console.log('Delete response data:', data);
                
                if (data.success) {
                    Toast.success(data.message || 'Member deleted successfully');
                    // Redirect to members page after a short delay
                    setTimeout(() => {
                        window.location.href = adminBase + '/?page=members';
                    }, 1000);
                } else {
                    console.error('Delete failed:', data);
                    Toast.error(data.message || 'Failed to delete member');
                }
            } catch (error) {
                console.error('Error deleting member:', error);
                console.error('Error stack:', error.stack);
                Toast.error(error.message || 'An error occurred while deleting');
            } finally {
                this.saving = false;
            }
        },

        async reactivateMember() {
            // Similar to delete but updates status
            this.saving = true;
            try {
                const response = await fetch(`${apiBaseUrl}/members/${this.memberId}`, {
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
                    await this.loadData();
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

        formatDate(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        },

        formatTime(timeString) {
            if (!timeString) return '';
            const time = new Date('2000-01-01 ' + timeString);
            return time.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        },

        formatDateTime(dateString) {
            if (!dateString) return '';
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) + ' ' +
                   date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        },

        calculateAge(dateOfBirth) {
            if (!dateOfBirth) return '';
            const birthDate = new Date(dateOfBirth);
            const today = new Date();
            let age = today.getFullYear() - birthDate.getFullYear();
            const monthDiff = today.getMonth() - birthDate.getMonth();
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birthDate.getDate())) {
                age--;
            }
            return age + ' years old';
        },

        formatRelationshipType(type) {
            if (!type) return '';
            const types = {
                'spouse': 'Spouse',
                'parent': 'Parent',
                'child': 'Child',
                'sibling': 'Sibling',
                'guardian': 'Guardian',
                'ward': 'Ward',
                'other': 'Other'
            };
            return types[type] || type.charAt(0).toUpperCase() + type.slice(1);
        },

        async copyToClipboard(text) {
            try {
                await navigator.clipboard.writeText(text);
                Toast.success('Copied to clipboard!');
            } catch (err) {
                // Fallback for older browsers
                const textArea = document.createElement('textarea');
                textArea.value = text;
                textArea.style.position = 'fixed';
                textArea.style.opacity = '0';
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    Toast.success('Copied to clipboard!');
                } catch (err) {
                    Toast.error('Failed to copy to clipboard');
                }
                document.body.removeChild(textArea);
            }
        },

        async copyAllCredentials() {
            if (!this.credentialsData) return;
            const text = `Email: ${this.credentialsData.email}\nPassword: ${this.credentialsData.password}`;
            await this.copyToClipboard(text);
        },

        async generateCredentials() {
            console.log('generateCredentials called');
            
            if (!this.memberData || !this.memberData.email) {
                Toast.error('Member must have an email address to generate credentials');
                return;
            }
            
            // Ensure confirmAction is available
            if (typeof confirmAction === 'undefined') {
                console.error('confirmAction is not defined. confirm.js may not be loaded.');
                // Try to wait a bit for confirm.js to load
                await new Promise(resolve => setTimeout(resolve, 100));
                if (typeof confirmAction === 'undefined') {
                    Toast.error('Confirmation dialog is not available. Please refresh the page.');
                    return;
                }
            }
            
            console.log('Showing confirmation dialog');
            
            // Verify modal exists in DOM
            const modalElement = document.getElementById('confirm-dialog-modal');
            console.log('Modal element exists:', !!modalElement);
            if (modalElement) {
                console.log('Modal display style:', window.getComputedStyle(modalElement).display);
                console.log('Modal classes:', modalElement.className);
            }
            
            const confirmed = await confirmAction({
                title: 'Generate Credentials',
                message: `Generate login credentials for ${this.memberData.first_name} ${this.memberData.last_name}? A new password will be created and displayed.`,
                type: 'info',
                okText: 'Generate',
                cancelText: 'Cancel'
            });
            
            console.log('Confirmation result:', confirmed);
            
            if (!confirmed) {
                console.log('User cancelled credentials generation');
                return;
            }
            
            // Small delay to ensure confirm dialog is fully closed
            await new Promise(resolve => setTimeout(resolve, 100));
            
            this.saving = true;
            try {
                console.log('Making API request to generate credentials');
                console.log('API URL:', `${API_BASE_URL}/members.php?action=generate_credentials&id=${this.memberId}`);
                const response = await fetch(`${API_BASE_URL}/members.php?action=generate_credentials&id=${this.memberId}`, {
                    method: 'POST',
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
                    throw new Error(errorData.message || 'Failed to generate credentials');
                }
                
                const data = await response.json();
                
                console.log('API response:', data);
                
                if (data.success && data.data) {
                    // Store credentials and show modal
                    this.credentialsData = data.data;
                    console.log('Credentials data:', this.credentialsData);
                    // Force modal to show with a small delay
                    await new Promise(resolve => setTimeout(resolve, 50));
                    this.showCredentialsModal = true;
                    console.log('Credentials modal should be visible now, showCredentialsModal:', this.showCredentialsModal);
                    Toast.success('Credentials generated successfully');
                } else {
                    console.error('API returned error:', data);
                    Toast.error(data.message || 'Failed to generate credentials');
                }
            } catch (error) {
                console.error('Error generating credentials:', error);
                console.error('Error stack:', error.stack);
                Toast.error(error.message || 'An error occurred while generating credentials');
            } finally {
                this.saving = false;
            }
        },

        async removeGroup(groupId) {
            // Find the group name for the confirmation message
            const group = this.groupsData.find(g => g.id == groupId);
            const groupName = group ? group.name : 'this group';
            
            const confirmed = await confirmAction({
                title: 'Remove from Group',
                message: `Are you sure you want to remove this member from "${groupName}"?`,
                type: 'warning',
                okText: 'Remove',
                cancelText: 'Cancel'
            });
            
            if (!confirmed) {
                return;
            }

            try {
                const response = await fetch(`${API_BASE_URL}/groups.php?action=remove`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        member_id: this.memberId,
                        group_id: groupId,
                        csrf_token: csrfToken
                    })
                });
                
                const data = await response.json();
                if (data.success) {
                    Toast.success('Member removed from group successfully');
                    // Reload data to update the groups display
                    await this.loadData();
                } else {
                    Toast.error(data.message || 'Failed to remove member from group');
                }
            } catch (error) {
                console.error('Error removing member from group:', error);
                Toast.error('Failed to remove member from group');
            }
        }
    };
}
</script>
