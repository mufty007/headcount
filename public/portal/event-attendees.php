<?php

/**
 * Event Attendees Page
 * Shows who else is attending an event (if permitted)
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

// Get event ID
$eventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;

if (!$eventId) {
    header('Location: events.php');
    exit;
}

// Calculate base URLs
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
$baseUrlPath = rtrim($baseUrlPath, '/');
$cssBase = $baseUrlPath . '/public/css/';
$apiBase = $baseUrlPath . '/api/portal/';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Event Attendees - Member Portal</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="<?php echo htmlspecialchars($cssBase); ?>main.css">
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4">
            <div class="flex justify-between items-center">
                <h1 class="text-2xl font-bold text-gray-900">Event Attendees</h1>
                <div class="flex gap-2">
                    <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/events.php" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900">Events</a>
                    <a href="<?php echo htmlspecialchars($baseUrlPath); ?>/portal/dashboard.php" class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900">Dashboard</a>
                </div>
            </div>
        </div>
    </header>

    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div id="event-info" class="bg-white rounded-lg shadow p-6 mb-6">
            <div class="text-center py-8 text-gray-500">Loading event information...</div>
        </div>

        <div class="bg-white rounded-lg shadow">
            <div class="p-6 border-b border-gray-200">
                <h2 class="text-xl font-semibold text-gray-900">Who's Attending</h2>
            </div>
            <div id="attendees-list" class="p-6">
                <div class="text-center py-8 text-gray-500">Loading attendees...</div>
            </div>
        </div>
    </div>

    <script>
        const apiBase = <?php echo json_encode($apiBase); ?>;
        const baseUrl = <?php echo json_encode($baseUrlPath); ?>;
        const eventId = <?php echo json_encode($eventId); ?>;

        // Load event and attendees
        async function loadData() {
            try {
                // Load event info
                const eventResponse = await fetch(apiBase + 'events/' + eventId);
                const eventData = await eventResponse.json();

                if (eventData.success && eventData.event) {
                    displayEventInfo(eventData.event);
                }

                // Load attendees
                const attendeesResponse = await fetch(apiBase + 'social/attendees/' + eventId);
                const attendeesData = await attendeesResponse.json();

                if (attendeesData.success) {
                    displayAttendees(attendeesData.attendees || []);
                }
            } catch (error) {
                console.error('Error:', error);
            }
        }

        function displayEventInfo(event) {
            const container = document.getElementById('event-info');
            const eventDate = new Date(event.event_date);
            const dateStr = eventDate.toLocaleDateString('en-US', { 
                month: 'long', 
                day: 'numeric', 
                year: 'numeric' 
            });
            
            container.innerHTML = `
                <h2 class="text-2xl font-bold text-gray-900 mb-2">${escapeHtml(event.title)}</h2>
                <p class="text-gray-600">${dateStr}</p>
                <a href="${baseUrl}/portal/event-details.php?id=${event.id}" 
                   class="mt-4 inline-block text-blue-600 hover:text-blue-700">
                    ← Back to Event Details
                </a>
            `;
        }

        function displayAttendees(attendees) {
            const container = document.getElementById('attendees-list');
            
            if (attendees.length === 0) {
                container.innerHTML = '<div class="text-center py-8 text-gray-500">No attendees yet</div>';
                return;
            }

            container.innerHTML = `
                <div class="text-sm text-gray-600 mb-4">${attendees.length} ${attendees.length === 1 ? 'person is' : 'people are'} attending</div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    ${attendees.map(attendee => `
                        <div class="p-4 bg-gray-50 rounded-lg">
                            <div class="flex items-center">
                                <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center mr-3">
                                    <span class="text-blue-600 font-semibold">${attendee.name.charAt(0).toUpperCase()}</span>
                                </div>
                                <div>
                                    <div class="font-medium text-gray-900">${escapeHtml(attendee.name)}</div>
                                </div>
                            </div>
                        </div>
                    `).join('')}
                </div>
            `;
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Load data on page load
        loadData();
    </script>
</body>
</html>
