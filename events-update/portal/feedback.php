<?php

/**
 * Event Feedback Page
 * Allows members to rate and provide feedback on events they attended
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

// Get event ID if provided
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

// Set page title
$pageTitle = 'Event Feedback';

// Include header
require __DIR__ . '/includes/header.php';
?>

<?php
$pageHeaderTitle = 'Event Feedback';
$pageHeaderSubtitle = 'Share your feedback on past events';
require __DIR__ . '/components/page-header.php';
?>

<div class="max-w-4xl">
        <div id="error-message" class="hidden rounded-lg border border-rose-300 bg-rose-50 px-4 py-3 text-sm text-rose-700 mb-4 dark:border-rose-900 dark:bg-rose-950/30 dark:text-rose-300"></div>
        <div id="success-message" class="hidden rounded-lg border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-700 mb-4 dark:border-green-900 dark:bg-green-950/30 dark:text-green-300"></div>

        <!-- Events I Attended (for feedback) -->
        <div class="bento-card mb-6">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Events I Attended</h2>
            </div>
            <div id="attended-events" class="p-6">
                <div class="text-center py-8 text-gray-500 dark:text-gray-400">Loading events...</div>
            </div>
        </div>

        <!-- Feedback Form (shown when event selected) -->
        <?php if ($eventId): ?>
        <div class="bento-card" id="feedback-form-container">
            <div class="p-6 border-b border-gray-200 dark:border-gray-700">
                <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Submit Feedback</h2>
            </div>
            <div class="p-6">
                <div id="event-info" class="mb-6"></div>
                <form id="feedback-form">
                    <input type="hidden" id="feedback_event_id" name="event_id" value="<?php echo $eventId; ?>">

                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Rating</label>
                        <div class="flex gap-1" id="rating-stars">
                            <?php for ($s = 1; $s <= 5; $s++): ?>
                            <button type="button" class="star-btn text-gray-300 dark:text-gray-600 transition-colors hover:text-amber-300" data-rating="<?= $s ?>" aria-label="<?= $s ?> star<?= $s > 1 ? 's' : '' ?>">
                                <svg class="h-8 w-8" fill="currentColor" viewBox="0 0 20 20"><path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.286 3.957a1 1 0 00.95.69h4.162c.969 0 1.371 1.24.588 1.81l-3.368 2.446a1 1 0 00-.364 1.118l1.287 3.957c.3.922-.755 1.688-1.54 1.118l-3.367-2.446a1 1 0 00-1.175 0l-3.367 2.446c-.784.57-1.838-.196-1.539-1.118l1.286-3.957a1 1 0 00-.363-1.118L2.075 10.05c-.783-.57-.38-1.81.588-1.81h4.162a1 1 0 00.95-.69l1.286-3.957z"/></svg>
                            </button>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" id="rating" name="rating" required>
                    </div>

                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Feedback (Optional)</label>
                        <textarea id="feedback_text" name="feedback_text" rows="4"
                                  class="w-full rounded-lg border border-gray-300 bg-white px-3.5 py-2.5 text-sm text-gray-800 placeholder-gray-400 transition-colors focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 dark:placeholder-gray-500"
                                  placeholder="Share your thoughts about the event..."></textarea>
                    </div>

                    <button type="submit" class="btn-primary px-6 py-2.5">
                        Submit Feedback
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
        const apiBase = <?php echo json_encode($apiBase); ?>;
        const baseUrl = <?php echo json_encode($baseUrlPath); ?>;
        const selectedEventId = <?php echo json_encode($eventId); ?>;

        let selectedRating = 0;

        // Load attended events
        async function loadAttendedEvents() {
            try {
                const response = await fetch(apiBase + 'dashboard/past');
                const data = await response.json();

                if (data.success) {
                    displayAttendedEvents(data.events || []);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        function displayAttendedEvents(events) {
            const container = document.getElementById('attended-events');
            
            if (events.length === 0) {
                container.innerHTML = '<div class="text-center py-8 text-gray-500 dark:text-gray-400">No past events found</div>';
                return;
            }

            container.innerHTML = events.map(event => {
                const eventDate = new Date(event.event_date);
                const dateStr = eventDate.toLocaleDateString('en-US', { 
                    month: 'short', 
                    day: 'numeric', 
                    year: 'numeric' 
                });
                
                return `
                    <div class="border-b border-gray-200 py-4 last:border-b-0 dark:border-gray-700">
                        <div class="flex justify-between items-start gap-4">
                            <div class="min-w-0">
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">${escapeHtml(event.title)}</h3>
                                <p class="text-sm text-gray-600 mt-1 dark:text-gray-400">${dateStr}</p>
                            </div>
                            <a href="${baseUrl}/portal/feedback.php?event_id=${event.id}"
                               class="btn-primary shrink-0 px-4 py-2 text-sm">
                                Provide Feedback
                            </a>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Star rating
        document.querySelectorAll('.star-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const rating = parseInt(this.dataset.rating);
                selectedRating = rating;
                document.getElementById('rating').value = rating;
                
                // Update star display
                document.querySelectorAll('.star-btn').forEach((star, index) => {
                    const on = index < rating;
                    star.classList.toggle('text-amber-400', on);
                    star.classList.toggle('text-gray-300', !on);
                    star.classList.toggle('dark:text-gray-600', !on);
                });
            });
        });

        // Load event info if event selected
        if (selectedEventId) {
            loadEventInfo(selectedEventId);
        }

        async function loadEventInfo(eventId) {
            try {
                const response = await fetch(apiBase + 'events/' + eventId);
                const data = await response.json();

                if (data.success && data.event) {
                    const event = data.event;
                    document.getElementById('event-info').innerHTML = `
                        <h3 class="text-xl font-semibold text-gray-900 mb-2">${escapeHtml(event.title)}</h3>
                        <p class="text-gray-600">${escapeHtml(event.description || '')}</p>
                    `;
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        // Feedback form submission
        if (document.getElementById('feedback-form')) {
            document.getElementById('feedback-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                const form = e.target;
                const submitBtn = form.querySelector('button[type="submit"]');
                const originalText = submitBtn.textContent;
                
                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting...';

                try {
                    const csrfToken = await getCSRFToken();
                    const formData = new FormData(form);
                    formData.append('csrf_token', csrfToken);

                    const response = await fetch(apiBase + 'feedback', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify(Object.fromEntries(formData))
                    });

                    const data = await response.json();

                    if (data.success) {
                        showSuccess('Feedback submitted successfully!');
                        setTimeout(() => {
                            window.location.href = baseUrl + '/portal/feedback.php';
                        }, 2000);
                    } else {
                        const errors = data.errors || [data.message || 'Submission failed'];
                        showError(errors.join(', '));
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                    }
                } catch (error) {
                    showError('An error occurred. Please try again.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                }
            });
        }

        async function getCSRFToken() {
            try {
                const response = await fetch(apiBase.replace('/portal/', '/') + 'csrf-token');
                const data = await response.json();
                return data.token || '';
            } catch (e) {
                return '';
            }
        }

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
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Load attended events on page load
        loadAttendedEvents();
    </script>
<?php require __DIR__ . '/includes/footer.php'; ?>
