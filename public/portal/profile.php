<?php

/**
 * Member Profile Page
 * Allows members to manage their profile
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Require authentication (redirects to login if not authenticated)
PortalAuthMiddleware::requireAuth();

$config = null;
try {
    // Load config
    $configFile = HC_PROJECT_ROOT . '/config/config.php';
    if (!file_exists($configFile)) {
        throw new \RuntimeException('Configuration file not found.');
    }
    $config = require $configFile;

    if (session_status() === PHP_SESSION_NONE) {
        Security::configureSession();
        session_start();
    }
    Database::getInstance($config['database']);
} catch (\Throwable $e) {
    error_log('Profile page init error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('Profile page trace: ' . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body>';
    echo '<h1>Something went wrong</h1><p>We couldn’t load this page. Please try again later.</p>';
    if (isset($config['app']['debug']) && $config['app']['debug']) {
        echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
        echo '<p><strong>File:</strong> ' . htmlspecialchars($e->getFile()) . ':' . $e->getLine() . '</p>';
    }
    echo '</body></html>';
    exit;
}

$member = PortalAuthMiddleware::getMember();

// Calculate base URLs
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
// Handle both /portal/ and /portal cases
if (preg_match('#/portal(/.*)?$#', $requestPath, $matches)) {
    $pos = strpos($requestPath, '/portal');
    $baseUrlPath = $pos !== false ? substr($requestPath, 0, $pos) : '';
} else {
    $baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
}
$baseUrlPath = rtrim($baseUrlPath, '/');
// Ensure baseUrlPath is not empty - default to root if empty
if (empty($baseUrlPath)) {
    $baseUrlPath = '';
}
$apiBase = $baseUrlPath . '/api/portal/';

// Set page title
$pageTitle = 'My Profile';

try {
    require __DIR__ . '/includes/header.php';
} catch (\Throwable $e) {
    error_log('Profile page header error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    error_log('Profile page header trace: ' . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Error</title></head><body>';
    echo '<h1>Something went wrong</h1><p>We couldn’t load this page. Please try again later.</p>';
    if (isset($config['app']['debug']) && $config['app']['debug']) {
        echo '<p><strong>Error:</strong> ' . htmlspecialchars($e->getMessage()) . '</p>';
    }
    echo '</body></html>';
    exit;
}
?>

<div class="mb-8">
    <h1 class="text-2xl md:text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">My Profile</h1>
    <p class="text-sm md:text-base text-gray-500 dark:text-gray-400 mt-1">Manage your personal information and preferences.</p>
</div>

<div class="max-w-4xl space-y-6">
    <div id="error-message" class="hidden bg-red-50 dark:bg-red-500/15 border border-red-200 dark:border-red-500/30 text-red-700 dark:text-red-300 px-4 py-3 rounded-xl animate-fade-in shadow-sm"></div>
    <div id="success-message" class="hidden bg-green-50 dark:bg-green-500/15 border border-green-200 dark:border-green-500/30 text-green-700 dark:text-green-300 px-4 py-3 rounded-xl animate-fade-in shadow-sm"></div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Sidebar: Photo & Tags -->
        <div class="lg:col-span-1 space-y-6">
            <!-- Profile Photo -->
            <div class="bento-card text-center">
                <div class="mb-6 relative inline-block">
                    <div id="photo-preview" class="w-32 h-32 md:w-40 md:h-40 bg-indigo-50 dark:bg-indigo-500/15 rounded-full flex items-center justify-center overflow-hidden border-4 border-white shadow-lg mx-auto">
                        <span class="text-indigo-300">
                            <svg width="64" height="64" class="w-16 h-16" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                        </span>
                    </div>
                    <label for="photo-input" class="absolute bottom-0 right-0 p-2 bg-indigo-600 text-white rounded-full shadow-lg cursor-pointer hover:bg-indigo-700 transition-colors">
                        <svg width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    </label>
                </div>
                <form id="photo-form" enctype="multipart/form-data" class="space-y-3">
                    <input type="file" id="photo-input" name="photo" accept="image/jpeg,image/png,image/gif" class="hidden" onchange="this.form.dispatchEvent(new Event('submit'))">
                    <p class="text-[10px] text-gray-500 dark:text-gray-400 uppercase tracking-widest font-bold">Square JPEG or PNG, max 5MB</p>
                </form>
            </div>

            <!-- Tags & Groups -->
            <div class="bento-card">
                <h3 class="text-sm font-bold text-gray-900 dark:text-white mb-4 uppercase tracking-widest">Tags & Groups</h3>
                <div id="tags-groups" class="space-y-4">
                    <div class="animate-pulse space-y-2">
                        <div class="h-4 bg-gray-100 dark:bg-gray-700 rounded w-3/4"></div>
                        <div class="h-4 bg-gray-100 dark:bg-gray-700 rounded w-1/2"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Form: Personal Info & Prefs -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Personal Info -->
            <div class="bento-card">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white">Personal Information</h2>
                    <div class="p-2 bg-indigo-50 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-300 rounded-lg">
                        <svg width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                    </div>
                </div>
                <form id="profile-form" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-1">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">First Name</label>
                            <input type="text" id="first_name" name="first_name" required class="w-full">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Last Name</label>
                            <input type="text" id="last_name" name="last_name" required class="w-full">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Email Address</label>
                            <input type="email" id="email" name="email" required class="w-full">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Phone Number</label>
                            <input type="tel" id="phone" name="phone" class="w-full">
                        </div>
                        <div class="space-y-1">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Gender</label>
                            <select id="gender" name="gender" class="w-full">
                                <option value="">Select...</option>
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        <div class="space-y-1">
                            <label class="block text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Date of Birth</label>
                            <input type="date" id="date_of_birth" name="date_of_birth" class="w-full">
                        </div>
                    </div>
                    <div class="pt-4">
                        <button type="submit" class="w-full md:w-auto px-8 py-3 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700 shadow-md shadow-indigo-200 transition-all active:scale-95">
                            Save Changes
                        </button>
                    </div>
                </form>
            </div>

            <!-- Preferences -->
            <div class="bento-card">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-bold text-gray-900 dark:text-white">Communication Preferences</h2>
                    <div class="p-2 bg-emerald-50 dark:bg-emerald-500/15 text-emerald-600 dark:text-emerald-300 rounded-lg">
                        <svg width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>
                    </div>
                </div>
                <form id="preferences-form" class="space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <label class="flex items-center p-4 bg-gray-50 dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-800 cursor-pointer hover:bg-white hover:border-indigo-200 transition-all group">
                            <input type="checkbox" id="pref_event_announcements" name="event_announcements" class="w-5 h-5 text-indigo-600 dark:text-indigo-300 rounded-lg border-gray-300 focus:ring-indigo-500 mr-4">
                            <div class="flex flex-col">
                                <span class="text-sm font-bold text-gray-800 dark:text-gray-100">Event Announcements</span>
                                <span class="text-[10px] text-gray-500 dark:text-gray-400">Get notified about new events</span>
                            </div>
                        </label>
                        <label class="flex items-center p-4 bg-gray-50 dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-800 cursor-pointer hover:bg-white hover:border-indigo-200 transition-all group">
                            <input type="checkbox" id="pref_event_reminders" name="event_reminders" class="w-5 h-5 text-indigo-600 dark:text-indigo-300 rounded-lg border-gray-300 focus:ring-indigo-500 mr-4">
                            <div class="flex flex-col">
                                <span class="text-sm font-bold text-gray-800 dark:text-gray-100">Event Reminders</span>
                                <span class="text-[10px] text-gray-500 dark:text-gray-400">Stay on top of your schedule</span>
                            </div>
                        </label>
                        <label class="flex items-center p-4 bg-gray-50 dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-800 cursor-pointer hover:bg-white hover:border-indigo-200 transition-all group">
                            <input type="checkbox" id="pref_rsvp_confirmations" name="rsvp_confirmations" class="w-5 h-5 text-indigo-600 dark:text-indigo-300 rounded-lg border-gray-300 focus:ring-indigo-500 mr-4">
                            <div class="flex flex-col">
                                <span class="text-sm font-bold text-gray-800 dark:text-gray-100">RSVP Confirmations</span>
                                <span class="text-[10px] text-gray-500 dark:text-gray-400">Confirmations for your sign-ups</span>
                            </div>
                        </label>
                        <label class="flex items-center p-4 bg-gray-50 dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-800 cursor-pointer hover:bg-white hover:border-indigo-200 transition-all group">
                            <input type="checkbox" id="pref_payment_receipts" name="payment_receipts" class="w-5 h-5 text-indigo-600 dark:text-indigo-300 rounded-lg border-gray-300 focus:ring-indigo-500 mr-4">
                            <div class="flex flex-col">
                                <span class="text-sm font-bold text-gray-800 dark:text-gray-100">Payment Receipts</span>
                                <span class="text-[10px] text-gray-500 dark:text-gray-400">Records of your transactions</span>
                            </div>
                        </label>
                    </div>
                    <div class="pt-2">
                        <button type="submit" class="w-full md:w-auto px-8 py-3 bg-indigo-600 text-white font-bold rounded-xl hover:bg-indigo-700 shadow-md shadow-indigo-200 transition-all active:scale-95">
                            Save Preferences
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

    </div>
</div>

    <script>
        const apiBase = <?php echo json_encode($apiBase); ?>;
        const baseUrl = <?php echo json_encode($baseUrlPath); ?>;

        // Load profile data
        async function loadProfile() {
            try {
                const response = await fetch(apiBase + 'profile');
                const data = await response.json();

                if (data.success && data.member) {
                    const member = data.member;
                    
                    // Populate form
                    document.getElementById('first_name').value = member.first_name || '';
                    document.getElementById('last_name').value = member.last_name || '';
                    document.getElementById('email').value = member.email || '';
                    document.getElementById('phone').value = member.phone || '';
                    document.getElementById('gender').value = member.gender || '';
                    document.getElementById('date_of_birth').value = member.date_of_birth || '';

                    // Set photo preview
                    if (member.profile_photo_path) {
                        const photoUrl = baseUrl + '/' + member.profile_photo_path;
                        document.getElementById('photo-preview').innerHTML = 
                            `<img src="${photoUrl}" alt="Profile" class="w-full h-full object-cover">`;
                    }

                    // Set preferences
                    const emailPrefs = member.email_preferences || {};
                    document.getElementById('pref_event_announcements').checked = emailPrefs.event_announcements !== false;
                    document.getElementById('pref_event_reminders').checked = emailPrefs.event_reminders !== false;
                    document.getElementById('pref_rsvp_confirmations').checked = emailPrefs.rsvp_confirmations !== false;
                    document.getElementById('pref_payment_receipts').checked = emailPrefs.payment_receipts !== false;

                    // Display tags and groups
                    displayTagsAndGroups(member.tags || [], member.groups || []);
                }
            } catch (error) {
                console.error('Error loading profile:', error);
            }
        }

        function displayTagsAndGroups(tags, groups) {
            const container = document.getElementById('tags-groups');
            
            let html = '';
            
            if (tags.length > 0) {
                html += '<div><h3 class="font-medium text-gray-900 dark:text-white mb-2">Tags</h3><div class="flex flex-wrap gap-2">';
                tags.forEach(tag => {
                    html += `<span class="px-3 py-1 text-sm rounded-full" style="background-color: ${tag.color || '#3B82F6'}20; color: ${tag.color || '#3B82F6'}">${escapeHtml(tag.name)}</span>`;
                });
                html += '</div></div>';
            }
            
            if (groups.length > 0) {
                html += '<div><h3 class="font-medium text-gray-900 dark:text-white mb-2">Groups</h3><div class="space-y-2">';
                groups.forEach(group => {
                    html += `<div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg"><div class="font-medium">${escapeHtml(group.name)}</div>${group.description ? '<div class="text-sm text-gray-600 dark:text-gray-300">' + escapeHtml(group.description) + '</div>' : ''}</div>`;
                });
                html += '</div></div>';
            }
            
            if (html === '') {
                html = '<div class="text-gray-500 dark:text-gray-400">No tags or groups assigned</div>';
            }
            
            container.innerHTML = html;
        }

        // Get CSRF token
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

        // Profile form submission
        document.getElementById('profile-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';

            try {
                const csrfToken = await getCSRFToken();
                const formData = new FormData(form);
                formData.append('csrf_token', csrfToken);

                const response = await fetch(apiBase + 'profile', {
                    method: 'PUT',
                    headers: {
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify(Object.fromEntries(formData))
                });

                const data = await response.json();

                if (data.success) {
                    showSuccess('Profile updated successfully');
                } else {
                    const errors = data.errors || [data.message || 'Update failed'];
                    showError(errors.join(', '));
                }
            } catch (error) {
                showError('An error occurred. Please try again.');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        });

        // Photo upload
        document.getElementById('photo-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            
            submitBtn.disabled = true;
            submitBtn.textContent = 'Uploading...';

            try {
                const csrfToken = await getCSRFToken();
                const formData = new FormData(form);
                formData.append('csrf_token', csrfToken);

                const response = await fetch(apiBase + 'profile/photo', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': csrfToken
                    },
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    showSuccess('Photo uploaded successfully');
                    // Update preview
                    if (data.photo_path) {
                        const photoUrl = baseUrl + '/' + data.photo_path;
                        document.getElementById('photo-preview').innerHTML = 
                            `<img src="${photoUrl}" alt="Profile" class="w-full h-full object-cover">`;
                    }
                } else {
                    showError(data.message || 'Upload failed');
                }
            } catch (error) {
                showError('An error occurred. Please try again.');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        });

        // Preferences form
        document.getElementById('preferences-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.target;
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.textContent;
            
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';

            try {
                const csrfToken = await getCSRFToken();
                const emailPrefs = {
                    event_announcements: document.getElementById('pref_event_announcements').checked,
                    event_reminders: document.getElementById('pref_event_reminders').checked,
                    rsvp_confirmations: document.getElementById('pref_rsvp_confirmations').checked,
                    payment_receipts: document.getElementById('pref_payment_receipts').checked
                };

                const response = await fetch(apiBase + 'profile/preferences', {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        email_preferences: emailPrefs,
                        csrf_token: csrfToken
                    })
                });

                const data = await response.json();

                if (data.success) {
                    showSuccess('Preferences updated successfully');
                } else {
                    showError(data.message || 'Update failed');
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

        // Load profile on page load
        loadProfile();
    </script>
<?php require __DIR__ . '/includes/footer.php'; ?>
