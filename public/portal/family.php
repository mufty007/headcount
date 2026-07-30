<?php

/**
 * Family Management Page
 * Allows members to manage family members
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Require authentication
PortalAuthMiddleware::requireAuth();

// Load config
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    die("Configuration not found.");
}

$config = require $configFile;

// Initialize database
try {
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    die("System initialization failed.");
}

$member = PortalAuthMiddleware::getMember();

// Calculate base URLs
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
$baseUrlPath = rtrim($baseUrlPath, '/');
$apiBase = $baseUrlPath . '/api/portal/';

// Set page title
$pageTitle = 'My Family';

// Include header
require __DIR__ . '/includes/header.php';
?>

<div class="mb-5 md:mb-8">
    <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">My Family</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage your family members and their event access.</p>
</div>

<div class="max-w-4xl space-y-4 md:space-y-6">
    <div id="error-message" class="hidden bg-red-50 dark:bg-red-500/15 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-300 px-4 py-3 rounded-xl animate-fade-in shadow-sm"></div>
    <div id="success-message" class="hidden bg-green-50 dark:bg-green-500/15 border border-green-200 dark:border-green-500/30 text-green-700 dark:text-green-300 px-4 py-3 rounded-xl animate-fade-in shadow-sm"></div>

    <!-- Add Family Member -->
    <div class="bento-card">
        <div class="flex items-center justify-between mb-5">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white">Add Family Member</h2>
            <div class="p-2 bg-indigo-50 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-300 rounded-lg">
                <svg width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path></svg>
            </div>
        </div>
        <form id="add-family-form" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 md:gap-4">
                <div class="space-y-1">
                    <label class="block text-xs font-bold text-gray-600 dark:text-gray-300 uppercase tracking-widest">First Name</label>
                    <input type="text" id="family_first_name" name="first_name" required placeholder="Enter first name"
                           class="w-full min-h-[44px]">
                </div>
                <div class="space-y-1">
                    <label class="block text-xs font-bold text-gray-600 dark:text-gray-300 uppercase tracking-widest">Last Name</label>
                    <input type="text" id="family_last_name" name="last_name" required placeholder="Enter last name"
                           class="w-full min-h-[44px]">
                </div>
                <div class="space-y-1">
                    <label class="block text-xs font-bold text-gray-600 dark:text-gray-300 uppercase tracking-widest">Date of Birth</label>
                    <input type="date" id="family_date_of_birth" name="date_of_birth"
                           class="w-full min-h-[44px]">
                </div>
                <div class="space-y-1">
                    <label class="block text-xs font-bold text-gray-600 dark:text-gray-300 uppercase tracking-widest">Relationship</label>
                    <select id="family_relationship" name="relationship" class="w-full min-h-[44px]">
                        <option value="">Select relationship...</option>
                        <option value="spouse">Spouse</option>
                        <option value="child">Child</option>
                        <option value="parent">Parent</option>
                        <option value="sibling">Sibling</option>
                        <option value="other">Other</option>
                    </select>
                </div>
            </div>
            <div class="pt-2">
                <button type="submit" 
                        class="w-full sm:w-auto min-h-[44px] px-6 py-3 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700 shadow-md shadow-indigo-200 transition-all active:scale-95">
                    Add Family Member
                </button>
            </div>
        </form>
    </div>

    <!-- Family Members List -->
    <div class="bento-card">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white">Family Members</h2>
            <div class="p-2 bg-emerald-50 dark:bg-emerald-500/15 text-emerald-600 dark:text-emerald-300 rounded-lg">
                <svg width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
            </div>
        </div>
        <div id="family-members-list" class="space-y-4">
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">
                <div class="animate-pulse flex flex-col items-center">
                    <div class="h-10 w-10 bg-gray-200 rounded-full mb-4"></div>
                    <div class="h-4 w-48 bg-gray-200 rounded mb-2"></div>
                    <div class="h-3 w-32 bg-gray-100 dark:bg-gray-700 rounded"></div>
                </div>
            </div>
        </div>
    </div>
</div>

    <script>
        const apiBase = <?php echo json_encode($apiBase); ?>;
        const baseUrl = <?php echo json_encode($baseUrlPath); ?>;

        // Load family members
        async function loadFamilyMembers() {
            try {
                const response = await fetch(apiBase + 'family');
                const data = await response.json();

                if (data.success) {
                    displayFamilyMembers(data.family_members || []);
                } else {
                    document.getElementById('family-members-list').innerHTML = 
                        '<div class="text-center py-8 text-red-500">Error loading family members</div>';
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('family-members-list').innerHTML = 
                    '<div class="text-center py-8 text-red-500">Error loading family members</div>';
            }
        }

        function displayFamilyMembers(members) {
            const container = document.getElementById('family-members-list');
            
            if (members.length === 0) {
                container.innerHTML = `
                    <div class="text-center py-10 bg-gray-50 dark:bg-gray-800 rounded-2xl border-2 border-dashed border-gray-200 dark:border-gray-700">
                        <div class="p-3 bg-white dark:bg-gray-800 rounded-full w-12 h-12 flex items-center justify-center mx-auto mb-3 shadow-sm">
                            <svg width="24" height="24" class="w-6 h-6 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path></svg>
                        </div>
                        <p class="text-sm text-gray-500 dark:text-gray-400">No family members added yet</p>
                    </div>`;
                return;
            }

            container.innerHTML = members.map(member => {
                const dob = member.date_of_birth ? new Date(member.date_of_birth).toLocaleDateString(undefined, {
                    year: 'numeric', month: 'long', day: 'numeric'
                }) : 'Not provided';
                
                return `
                    <div class="flex items-center justify-between gap-3 p-3 sm:p-4 bg-gray-50 dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-800 hover:border-indigo-200 hover:bg-white transition-all group">
                        <div class="flex items-center min-w-0 flex-1">
                            <div class="w-11 h-11 sm:w-12 sm:h-12 bg-indigo-100 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-300 rounded-full flex items-center justify-center mr-3 flex-shrink-0 group-hover:bg-indigo-600 group-hover:text-white transition-colors">
                                <span class="text-sm sm:text-lg font-bold">${member.first_name.charAt(0)}${member.last_name.charAt(0)}</span>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-sm sm:text-base font-bold text-gray-900 dark:text-white truncate">${escapeHtml(member.first_name)} ${escapeHtml(member.last_name)}</h3>
                                <div class="flex flex-wrap gap-x-3 gap-y-1 mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    ${member.relationship ? `<span class="flex items-center"><svg width="12" height="12" class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>${escapeHtml(member.relationship)}</span>` : ''}
                                    <span class="flex items-center"><svg width="12" height="12" class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>${dob}</span>
                                </div>
                            </div>
                        </div>
                        <button onclick="removeFamilyMember(${member.id})" 
                                class="portal-touch-target flex-shrink-0 px-3 py-2 text-sm font-bold text-red-600 dark:text-red-300 bg-red-50 dark:bg-red-500/15 rounded-xl hover:bg-red-600 hover:text-white transition-all active:scale-95 inline-flex items-center justify-center gap-1.5"
                                aria-label="Remove ${escapeHtml(member.first_name)}">
                            <svg width="16" height="16" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-4v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                            <span class="hidden sm:inline">Remove</span>
                        </button>
                    </div>
                `;
            }).join('');
        }

        async function removeFamilyMember(id) {
            if (!confirm('Are you sure you want to remove this family member?')) {
                return;
            }

            try {
                const csrfToken = await getCSRFToken();
                const response = await fetch(apiBase + 'family/' + id, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        csrf_token: csrfToken
                    })
                });

                const data = await response.json();

                if (data.success) {
                    showSuccess('Family member removed');
                    loadFamilyMembers();
                } else {
                    showError(data.message || 'Failed to remove family member');
                }
            } catch (error) {
                console.error('Error:', error);
                showError('An error occurred. Please try again.');
            }
        }

        async function getCSRFToken() {
            try {
                const response = await fetch((baseUrl || '') + '/api/csrf-token', {
                    method: 'GET',
                    credentials: 'same-origin',
                    headers: { 'Accept': 'application/json' }
                });
                if (!response.ok) {
                    return '';
                }
                const data = await response.json();
                return data.token || '';
            } catch (e) {
                return '';
            }
        }

        // Add family member form
        document.getElementById('add-family-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            
            submitBtn.disabled = true;
            submitBtn.textContent = 'Adding...';

            try {
                const csrfToken = await getCSRFToken();
                const formData = new FormData(form);
                formData.append('csrf_token', csrfToken);

                const response = await fetch(apiBase + 'family', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify(Object.fromEntries(formData))
                });

                const data = await response.json();

                if (data.success) {
                    showSuccess('Family member added successfully');
                    form.reset();
                    loadFamilyMembers();
                } else {
                    const errors = data.errors || [data.message || 'Failed to add family member'];
                    showError(errors.join(', '));
                }
            } catch (error) {
                showError('An error occurred. Please try again.');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        });

        function showError(message) {
            const el = document.getElementById('error-message');
            el.textContent = message;
            el.classList.remove('hidden');
            setTimeout(() => el.classList.add('hidden'), 5000);
        }

        function showSuccess(message) {
            const el = document.getElementById('success-message');
            el.textContent = message;
            el.classList.remove('hidden');
            setTimeout(() => el.classList.add('hidden'), 5000);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Load family members on page load
        loadFamilyMembers();
    </script>
<?php require __DIR__ . '/includes/footer.php'; ?>
