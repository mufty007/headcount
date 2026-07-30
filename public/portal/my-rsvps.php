<?php

/**
 * My RSVPs Page
 * Allows members to manage their RSVPs
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
$pageTitle = 'My RSVPs';

// Include header
require __DIR__ . '/includes/header.php';
?>

<div class="mb-5 md:mb-8">
    <h1 class="text-xl sm:text-2xl md:text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">My RSVPs</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage your event reservations and history.</p>
</div>

<div class="space-y-4 md:space-y-6">
    <!-- Filters -->
    <div class="portal-filters !p-3 sm:!p-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
                <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-widest dark:text-gray-400 mb-1">Status</label>
                <select id="status-filter" class="w-full min-h-[44px]">
                    <option value="">All Statuses</option>
                    <option value="yes">Confirmed</option>
                    <option value="maybe">Maybe</option>
                    <option value="no">Cancelled</option>
                </select>
            </div>
            <div>
                <label class="block text-[11px] font-bold text-gray-500 uppercase tracking-widest dark:text-gray-400 mb-1">Date</label>
                <select id="date-filter" class="w-full min-h-[44px]">
                    <option value="">All Dates</option>
                    <option value="upcoming">Upcoming Events</option>
                    <option value="past">Past Events</option>
                </select>
            </div>
        </div>
    </div>

    <!-- RSVPs List -->
    <div id="rsvps-container" class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div class="col-span-full text-center py-20">
            <div class="animate-spin rounded-full h-10 w-10 border-4 border-indigo-500 border-t-transparent mx-auto mb-4"></div>
            <p class="text-gray-500 dark:text-gray-400 font-medium">Fetching your RSVPs...</p>
        </div>
    </div>

    <!-- Request Refund Modal -->
    <div id="refund-modal" class="fixed inset-0 z-50 flex items-center justify-center p-4" style="display: none;">
        <div class="absolute inset-0 bg-black/50" onclick="closeRefundModal()"></div>
        <div class="relative bg-white dark:bg-gray-800 rounded-2xl shadow-xl max-w-md w-full p-6">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Request Refund</h3>
            <p id="refund-modal-event-title" class="text-sm text-gray-600 dark:text-gray-300 mb-4"></p>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Reason (required)</label>
            <textarea id="refund-reason" rows="3" class="w-full border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2 text-sm mb-4" placeholder="e.g. Could not attend due to schedule change"></textarea>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeRefundModal()" class="px-4 py-2 text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg text-sm font-medium">Cancel</button>
                <button type="button" onclick="submitRefundRequest()" class="px-4 py-2 bg-amber-600 text-white rounded-lg text-sm font-medium hover:bg-amber-700">Submit Request</button>
            </div>
        </div>
    </div>
</div>

    <script>
        const apiBase = <?php echo json_encode($apiBase); ?>;
        const baseUrl = <?php echo json_encode($baseUrlPath); ?>;

        let allRSVPs = [];

        // Load RSVPs
        async function loadRSVPs() {
            try {
                const response = await fetch(apiBase + 'rsvps/my');
                const data = await response.json();

                if (data.success) {
                    allRSVPs = data.rsvps || [];
                    displayRSVPs(allRSVPs);
                } else {
                    document.getElementById('rsvps-container').innerHTML = 
                        '<div class="text-center py-12 text-red-500">Error loading RSVPs</div>';
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('rsvps-container').innerHTML = 
                    '<div class="text-center py-12 text-red-500">Error loading RSVPs</div>';
            }
        }

        function displayRSVPs(rsvps) {
            const container = document.getElementById('rsvps-container');
            
            if (rsvps.length === 0) {
                container.innerHTML = `
                    <div class="col-span-full bento-card py-16 text-center">
                        <div class="bg-gray-50 dark:bg-gray-800 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
                            <svg width="32" height="32" class="w-8 h-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900 dark:text-white">No RSVPs Found</h3>
                        <p class="text-gray-500 dark:text-gray-400 mt-1 max-w-xs mx-auto">You haven't made any event reservations that match your current filters.</p>
                        <a href="${baseUrl}/portal/events.php" class="inline-flex items-center text-indigo-600 dark:text-indigo-300 font-bold mt-6 hover:text-indigo-700">
                            Browse Events <svg width="16" height="16" class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path></svg>
                        </a>
                    </div>
                `;
                return;
            }

            container.innerHTML = rsvps.map(rsvp => {
                const { dateStr, timeStr, date: eventDate } = headcountFormatEventDateTime(rsvp);
                
                const isPast = headcountIsEventDatePast(rsvp.event_date);
                const statusColors = {
                    'yes': 'bg-emerald-500',
                    'maybe': 'bg-amber-500',
                    'no': 'bg-rose-500'
                };
                const statusLabels = {
                    'yes': 'Confirmed',
                    'maybe': 'Maybe',
                    'no': 'Cancelled'
                };
                
                const statusColor = statusColors[rsvp.rsvp_status] || 'bg-gray-500';
                const statusLabel = statusLabels[rsvp.rsvp_status] || rsvp.rsvp_status;
                
                return `
                    <div class="bento-card overflow-hidden group !p-0">
                        <div class="h-2 w-full ${statusColor}"></div>
                        <div class="p-4 sm:p-6">
                            <div class="flex justify-between items-start mb-4 gap-2">
                                <div class="min-w-0">
                                    <h3 class="text-base sm:text-lg font-bold text-gray-900 dark:text-white group-hover:text-indigo-600 transition-colors leading-snug">${escapeHtml(rsvp.title)}</h3>
                                    <div class="flex flex-wrap items-center mt-1 gap-2">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase tracking-wider text-white ${statusColor}">
                                            ${statusLabel}
                                        </span>
                                        ${isPast ? '<span class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Past Event</span>' : ''}
                                    </div>
                                </div>
                            </div>

                            <div class="space-y-2 mb-6">
                                <div class="flex items-center text-sm text-gray-600 dark:text-gray-300">
                                    <svg width="16" height="16" class="w-4 h-4 mr-2 text-gray-400 dark:text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                    <span>${dateStr}${timeStr ? ' • ' + timeStr : ''}</span>
                                </div>
                                <div class="flex items-start text-sm text-gray-600 dark:text-gray-300 min-w-0">
                                    <svg width="16" height="16" class="w-4 h-4 mr-2 mt-0.5 text-gray-400 dark:text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                    <span class="line-clamp-2 break-words">${escapeHtml(rsvp.location || 'Online / TBA')}</span>
                                </div>
                                ${rsvp.potluck_category || rsvp.potluck_category_label ? `
                                <div class="text-sm text-gray-600 dark:text-gray-300 bg-amber-50 dark:bg-amber-500/10 p-3 rounded-xl mt-2">
                                    <p class="font-semibold text-amber-900 dark:text-amber-200 text-xs uppercase tracking-wide mb-1">Potluck</p>
                                    <p>${escapeHtml(rsvp.potluck_category_label || rsvp.potluck_category || '')}${rsvp.potluck_item_note ? ' — ' + escapeHtml(rsvp.potluck_item_note) : ''}</p>
                                    ${rsvp.potluck_party_adults != null ? `<p class="text-xs text-gray-500 dark:text-gray-400 mt-1">${rsvp.potluck_party_adults || 0} adults, ${rsvp.potluck_party_children || 0} children</p>` : ''}
                                </div>` : ''}
                                ${rsvp.rsvp_notes && !String(rsvp.rsvp_notes).startsWith('Guests:') ? `
                                <div class="flex items-start text-sm text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-800 p-3 rounded-xl mt-3">
                                    <svg width="16" height="16" class="w-4 h-4 mr-2 text-gray-400 dark:text-gray-500 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"></path></svg>
                                    <p class="italic">"${escapeHtml(rsvp.rsvp_notes)}"</p>
                                </div>` : ''}
                            </div>

                            <div class="flex flex-col gap-2">
                                <div class="grid grid-cols-2 gap-2">
                                <a href="${baseUrl}/portal/event-details.php?id=${rsvp.event_id}" 
                                   class="min-h-[44px] inline-flex items-center justify-center px-4 py-2.5 bg-indigo-50 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-300 text-xs font-bold rounded-xl hover:bg-indigo-100 transition-all text-center">
                                    Details
                                </a>
                                <button onclick="downloadCalendar(${rsvp.event_id})" 
                                        class="min-h-[44px] inline-flex items-center justify-center px-4 py-2.5 bg-gray-50 dark:bg-gray-800 text-gray-600 dark:text-gray-300 text-xs font-bold rounded-xl hover:bg-gray-100 dark:hover:bg-gray-700/60 transition-all text-center">
                                    Add Cal
                                </button>
                                </div>
                                ${!isPast && rsvp.rsvp_status === 'yes' ? `
                                <a href="${baseUrl}/portal/event-details.php?id=${rsvp.event_id}&edit_rsvp=1"
                                   class="min-h-[44px] inline-flex items-center justify-center w-full px-4 py-2.5 bg-indigo-600 text-white text-xs font-bold rounded-xl hover:bg-indigo-700 transition-all text-center">
                                    Manage RSVP
                                </a>
                                <button onclick="cancelRSVP(${rsvp.id}, ${rsvp.payment_id ? 'true' : 'false'})" 
                                        class="min-h-[44px] inline-flex items-center justify-center w-full px-4 py-2.5 bg-rose-50 dark:bg-rose-500/15 text-rose-600 dark:text-rose-300 text-xs font-bold rounded-xl hover:bg-rose-100 transition-all text-center">
                                    Cancel RSVP
                                </button>
                                ` : ''}
                                ${isPast && !rsvp.checked_in && rsvp.payment_id ? `
                                <button type="button" data-refund-event-id="${rsvp.event_id}" data-refund-title="${escapeHtml(rsvp.title).replace(/"/g, '&quot;')}" onclick="openRefundModal(this)" 
                                        class="min-h-[44px] inline-flex items-center justify-center w-full px-4 py-2.5 bg-amber-50 dark:bg-amber-500/15 text-amber-700 dark:text-amber-300 text-xs font-bold rounded-xl hover:bg-amber-100 transition-all text-center">
                                    Request Refund
                                </button>
                                ` : ''}
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        async function cancelRSVP(rsvpId, hasPayment) {
            let msg = 'Are you sure you want to cancel this RSVP?';
            if (hasPayment) {
                msg += '\n\nPayment is not automatically refunded — contact the organization or request a refund after the event if applicable.';
            }
            if (!confirm(msg)) {
                return;
            }

            try {
                const csrfToken = await getCSRFToken();
                const response = await fetch(apiBase + 'rsvps/' + rsvpId, {
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
                    alert('RSVP cancelled successfully');
                    loadRSVPs();
                } else {
                    alert(data.message || 'Failed to cancel RSVP');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            }
        }

        async function downloadCalendar(eventId) {
            try {
                // Download ICS file
                window.location.href = apiBase + 'calendar/event/' + eventId + '.ics';
            } catch (error) {
                console.error('Error:', error);
                alert('Error downloading calendar file');
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

        // Filter handlers
        document.getElementById('status-filter').addEventListener('change', filterRSVPs);
        document.getElementById('date-filter').addEventListener('change', filterRSVPs);

        function filterRSVPs() {
            const statusFilter = document.getElementById('status-filter').value;
            const dateFilter = document.getElementById('date-filter').value;
            
            let filtered = allRSVPs;
            
            if (statusFilter) {
                filtered = filtered.filter(rsvp => rsvp.rsvp_status === statusFilter);
            }
            
            if (dateFilter) {
                if (dateFilter === 'upcoming') {
                    filtered = filtered.filter(rsvp => !headcountIsEventDatePast(rsvp.event_date));
                } else if (dateFilter === 'past') {
                    filtered = filtered.filter(rsvp => headcountIsEventDatePast(rsvp.event_date));
                }
            }
            
            displayRSVPs(filtered);
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        let refundEventId = null;
        let refundEventTitle = '';

        function openRefundModal(btn) {
            refundEventId = parseInt(btn.getAttribute('data-refund-event-id'), 10);
            refundEventTitle = btn.getAttribute('data-refund-title') || '';
            document.getElementById('refund-modal-event-title').textContent = refundEventTitle;
            document.getElementById('refund-reason').value = '';
            document.getElementById('refund-modal').style.display = 'flex';
        }

        function closeRefundModal() {
            document.getElementById('refund-modal').style.display = 'none';
            refundEventId = null;
        }

        async function submitRefundRequest() {
            const reason = document.getElementById('refund-reason').value.trim();
            if (!reason) {
                alert('Please enter a reason for your refund request.');
                return;
            }
            if (!refundEventId) return;
            try {
                const res = await fetch(apiBase + 'refund-requests.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ event_id: refundEventId, reason: reason }),
                    credentials: 'same-origin'
                });
                const data = await res.json();
                if (data.success) {
                    closeRefundModal();
                    alert(data.message || 'Refund request submitted.');
                    loadRSVPs();
                } else {
                    alert(data.message || 'Request failed.');
                }
            } catch (e) {
                console.error(e);
                alert('An error occurred. Please try again.');
            }
        }

        // Load RSVPs after shared portal scripts (portal-dates.js) are available
        document.addEventListener('DOMContentLoaded', loadRSVPs);
    </script>
<?php require __DIR__ . '/includes/footer.php'; ?>
