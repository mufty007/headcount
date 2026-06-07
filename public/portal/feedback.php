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

<div class="mb-8">
    <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">Event Feedback</h1>
    <p class="text-gray-500 mt-1">Share your feedback on past events</p>
</div>

<div class="max-w-4xl">
        <div id="error-message" class="hidden bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4"></div>
        <div id="success-message" class="hidden bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4"></div>

        <!-- Events I Attended (for feedback) -->
        <div class="bento-card mb-6">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">Events I Attended</h2>
            </div>
            <div id="attended-events" class="p-6">
                <div class="text-center py-8 text-gray-500">Loading events...</div>
            </div>
        </div>

        <!-- Feedback Form (shown when event selected) -->
        <?php if ($eventId): ?>
        <div class="bento-card" id="feedback-form-container">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">Submit Feedback</h2>
            </div>
            <div class="p-6">
                <div id="event-info" class="mb-6"></div>
                <form id="feedback-form">
                    <input type="hidden" id="feedback_event_id" name="event_id" value="<?php echo $eventId; ?>">
                    
                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Rating</label>
                        <div class="flex gap-2" id="rating-stars">
                            <button type="button" class="star-btn text-3xl" data-rating="1">☆</button>
                            <button type="button" class="star-btn text-3xl" data-rating="2">☆</button>
                            <button type="button" class="star-btn text-3xl" data-rating="3">☆</button>
                            <button type="button" class="star-btn text-3xl" data-rating="4">☆</button>
                            <button type="button" class="star-btn text-3xl" data-rating="5">☆</button>
                        </div>
                        <input type="hidden" id="rating" name="rating" required>
                    </div>

                    <div class="mb-6">
                        <label class="block text-sm font-medium text-gray-700 mb-2">Feedback (Optional)</label>
                        <textarea id="feedback_text" name="feedback_text" rows="4"
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                                  placeholder="Share your thoughts about the event..."></textarea>
                    </div>

                    <button type="submit" 
                            class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
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
                container.innerHTML = '<div class="text-center py-8 text-gray-500">No past events found</div>';
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
                    <div class="border-b border-gray-200 py-4 last:border-b-0">
                        <div class="flex justify-between items-start">
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900">${escapeHtml(event.title)}</h3>
                                <p class="text-sm text-gray-600 mt-1">${dateStr}</p>
                            </div>
                            <a href="${baseUrl}/portal/feedback.php?event_id=${event.id}" 
                               class="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">
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
                    if (index < rating) {
                        star.textContent = '★';
                        star.classList.add('text-yellow-400');
                    } else {
                        star.textContent = '☆';
                        star.classList.remove('text-yellow-400');
                    }
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
