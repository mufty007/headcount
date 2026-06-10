<?php

/**
 * Member Dashboard
 * Requires authentication
 */

require_once __DIR__ . '/bootstrap.php';
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

// Require authentication
PortalAuthMiddleware::requireAuth();

$member = PortalAuthMiddleware::getMember();

// Load config
$configFile = HC_PROJECT_ROOT . '/config/config.php';
if (!file_exists($configFile)) {
    die("Configuration not found.");
}

$config = require $configFile;

// Initialize database
try {
    Security::configureSession();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    Database::getInstance($config['database']);
} catch (\Exception $e) {
    error_log("Dashboard initialization error: " . $e->getMessage());
    error_log("Dashboard initialization error trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo "<h1>500 - Internal Server Error</h1>";
    echo "<p>System initialization failed.</p>";
    if (isset($config['app']['debug']) && $config['app']['debug']) {
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    }
    exit;
} catch (\Throwable $e) {
    error_log("Dashboard initialization fatal error: " . $e->getMessage());
    error_log("Dashboard initialization fatal error trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo "<h1>500 - Internal Server Error</h1>";
    echo "<p>System initialization failed.</p>";
    if (isset($config['app']['debug']) && $config['app']['debug']) {
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    }
    exit;
}

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
$portalBase = $baseUrlPath . '/portal';

// Set page title
$pageTitle = 'Dashboard';

// Include header
require __DIR__ . '/includes/header.php';
?>

<div class="mb-8">
    <div class="flex flex-col md:flex-row md:items-end md:justify-between gap-4">
        <div>
            <h1 class="text-2xl md:text-3xl font-extrabold text-gray-900 dark:text-white tracking-tight">Dashboard Overview</h1>
            <p class="text-sm md:text-base text-gray-500 dark:text-gray-400 mt-1">Welcome back, <span class="text-indigo-600 dark:text-indigo-300 font-semibold"><?= e($member['first_name'] ?? explode(' ', $member['name'])[0]) ?></span>. Here's what's happening.</p>
        </div>
        <div class="flex items-center space-x-2">
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 dark:bg-green-500/15 text-green-800 dark:text-green-300">
                <span class="w-2 h-2 mr-1.5 bg-green-500 rounded-full animate-pulse"></span>
                Live Updates
            </span>
        </div>
    </div>
</div>

<!-- Stats Grid (Modern Bento Style) -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 mb-10">
    <div class="bento-card relative overflow-hidden group">
        <div class="absolute top-0 right-0 w-16 h-16 -mr-4 -mt-4 bg-indigo-500/10 rounded-full group-hover:scale-150 transition-transform duration-500"></div>
        <div class="flex flex-col">
            <div class="flex items-center justify-between mb-4">
                <div class="p-2 bg-indigo-50 dark:bg-indigo-500/15 text-indigo-600 dark:text-indigo-300 rounded-lg">
                    <svg width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                </div>
                <span class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Attended</span>
            </div>
            <div class="text-2xl md:text-3xl font-bold text-gray-900 dark:text-white" id="stat-attended">-</div>
            <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">Check-ins</p>
        </div>
    </div>
    
    <div class="bento-card relative overflow-hidden group">
        <div class="absolute top-0 right-0 w-16 h-16 -mr-4 -mt-4 bg-emerald-500/10 rounded-full group-hover:scale-150 transition-transform duration-500"></div>
        <div class="flex flex-col">
            <div class="flex items-center justify-between mb-4">
                <div class="p-2 bg-emerald-50 dark:bg-emerald-500/15 text-emerald-600 dark:text-emerald-300 rounded-lg">
                    <svg width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                </div>
                <span class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Upcoming</span>
            </div>
            <div class="text-2xl md:text-3xl font-bold text-gray-900 dark:text-white" id="stat-upcoming">-</div>
            <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">Scheduled</p>
        </div>
    </div>
    
    <div class="bento-card relative overflow-hidden group">
        <div class="absolute top-0 right-0 w-16 h-16 -mr-4 -mt-4 bg-amber-500/10 rounded-full group-hover:scale-150 transition-transform duration-500"></div>
        <div class="flex flex-col">
            <div class="flex items-center justify-between mb-4">
                <div class="p-2 bg-amber-50 dark:bg-amber-500/15 text-amber-600 dark:text-amber-300 rounded-lg">
                    <svg width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path></svg>
                </div>
                <span class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">RSVPs</span>
            </div>
            <div class="text-2xl md:text-3xl font-bold text-gray-900 dark:text-white" id="stat-rsvps">-</div>
            <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">Confirmed</p>
        </div>
    </div>
    
    <div class="bento-card relative overflow-hidden group">
        <div class="absolute top-0 right-0 w-16 h-16 -mr-4 -mt-4 bg-red-500/10 rounded-full group-hover:scale-150 transition-transform duration-500"></div>
        <div class="flex flex-col">
            <div class="flex items-center justify-between mb-4">
                <div class="p-2 bg-red-50 dark:bg-red-500/15 text-red-600 dark:text-red-300 rounded-lg">
                    <svg width="20" height="20" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </div>
                <span class="text-[10px] font-bold text-gray-400 dark:text-gray-500 uppercase tracking-widest">Missed</span>
            </div>
            <div class="text-2xl md:text-3xl font-bold text-gray-900 dark:text-white" id="stat-noshows">-</div>
            <p class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">No-shows</p>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 md:gap-8">
    <!-- Upcoming Events -->
    <div class="bento-card flex flex-col h-full">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h2 class="font-bold text-gray-900 dark:text-white">My Upcoming Events</h2>
                <p class="text-[10px] text-gray-500 dark:text-gray-400">Your scheduled attendance</p>
            </div>
            <a href="<?= e($portalBase . '/my-rsvps.php') ?>" class="inline-flex items-center px-3 py-1 text-xs font-semibold text-indigo-600 dark:text-indigo-300 bg-indigo-50 dark:bg-indigo-500/15 rounded-full hover:bg-indigo-100 transition-colors">
                View All
                <svg class="w-3 h-3 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
            </a>
        </div>
        <div id="upcoming-events" class="space-y-3 flex-1">
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">Loading...</div>
        </div>
    </div>

    <!-- Past Attendance -->
    <div class="bento-card flex flex-col h-full">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h2 class="font-bold text-gray-900 dark:text-white">Past Attendance</h2>
                <p class="text-[10px] text-gray-500 dark:text-gray-400">Events you've checked in to</p>
            </div>
        </div>
        <div id="past-events" class="space-y-3 flex-1">
            <div class="text-center py-8 text-gray-500 dark:text-gray-400">Loading...</div>
        </div>
    </div>
</div>

    <script>
        const apiBase = <?php echo json_encode($apiBase); ?>;
        const baseUrl = <?php echo json_encode($baseUrlPath); ?>;

        // Load dashboard data
        async function loadDashboard() {
            try {
                // Load stats
                const statsResponse = await fetch(apiBase + 'dashboard/stats');
                const statsData = await statsResponse.json();
                
                if (statsData.success) {
                    document.getElementById('stat-attended').textContent = statsData.stats.events_attended || 0;
                    document.getElementById('stat-upcoming').textContent = statsData.stats.upcoming_count || 0;
                    document.getElementById('stat-rsvps').textContent = statsData.stats.events_signed_up || 0;
                    document.getElementById('stat-noshows').textContent = statsData.stats.no_shows || 0;
                }

                // Load upcoming events
                const upcomingResponse = await fetch(apiBase + 'dashboard/upcoming');
                const upcomingData = await upcomingResponse.json();
                
                if (upcomingData.success) {
                    displayUpcomingEvents(upcomingData.events || []);
                }

                // Load past events
                const pastResponse = await fetch(apiBase + 'dashboard/past');
                const pastData = await pastResponse.json();
                
                if (pastData.success) {
                    displayPastEvents(pastData.events || []);
                }
            } catch (error) {
                console.error('Error loading dashboard:', error);
            }
        }

        function displayUpcomingEvents(events) {
            const container = document.getElementById('upcoming-events');
            
            if (events.length === 0) {
                container.innerHTML = '<div class="text-center py-10"><div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-full w-12 h-12 flex items-center justify-center mx-auto mb-3"><svg width="24" height="24" class="w-6 h-6 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg></div><p class="text-xs text-gray-500 dark:text-gray-400">No upcoming events</p></div>';
                return;
            }

            container.innerHTML = events.map(event => {
                const eventDate = new Date(event.event_date);
                const dateStr = eventDate.toLocaleDateString('en-US', { 
                    month: 'short', 
                    day: 'numeric', 
                    year: 'numeric' 
                });
                const timeStr = event.start_time ? new Date('1970-01-01T' + event.start_time).toLocaleTimeString('en-US', { 
                    hour: 'numeric', 
                    minute: '2-digit' 
                }) : '';
                
                return `
                    <div class="group flex items-center p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors border border-transparent hover:border-gray-100">
                        <div class="w-10 h-10 rounded-lg bg-indigo-50 dark:bg-indigo-500/15 flex flex-col items-center justify-center mr-4 group-hover:bg-indigo-600 group-hover:text-white transition-colors">
                            <span class="text-[8px] uppercase font-bold">${eventDate.toLocaleDateString('en-US', { month: 'short' })}</span>
                            <span class="text-sm font-bold leading-none">${eventDate.getDate()}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-white truncate">${escapeHtml(event.title)}</h4>
                            <p class="text-[10px] text-gray-500 dark:text-gray-400">${dateStr}${timeStr ? ' at ' + timeStr : ''} • ${escapeHtml(event.location || '')}</p>
                        </div>
                        <a href="${baseUrl}/portal/event-details.php?id=${event.id}" class="p-2 text-gray-400 dark:text-gray-500 hover:text-indigo-600 opacity-0 group-hover:opacity-100 transition-opacity">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"></path></svg>
                        </a>
                    </div>
                `;
            }).join('');
        }

        function displayPastEvents(events) {
            const container = document.getElementById('past-events');
            
            if (events.length === 0) {
                container.innerHTML = '<div class="text-center py-10"><div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-full w-12 h-12 flex items-center justify-center mx-auto mb-3"><svg width="24" height="24" class="w-6 h-6 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg></div><p class="text-xs text-gray-500 dark:text-gray-400">No past attendance found</p></div>';
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
                    <div class="group flex items-center p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors border border-transparent hover:border-gray-100">
                        <div class="w-10 h-10 rounded-lg bg-green-50 dark:bg-green-500/15 flex flex-col items-center justify-center mr-4">
                            <span class="text-[8px] uppercase font-bold text-green-600 dark:text-green-300">${eventDate.toLocaleDateString('en-US', { month: 'short' })}</span>
                            <span class="text-sm font-bold leading-none text-green-600 dark:text-green-300">${eventDate.getDate()}</span>
                        </div>
                        <div class="flex-1 min-w-0">
                            <h4 class="text-sm font-semibold text-gray-900 dark:text-white truncate">${escapeHtml(event.title)}</h4>
                            <p class="text-[10px] text-gray-500 dark:text-gray-400">${dateStr} • ${escapeHtml(event.location || '')}</p>
                        </div>
                        <span class="px-2 py-1 text-[10px] font-medium bg-green-100 dark:bg-green-500/15 text-green-800 dark:text-green-300 rounded">Attended</span>
                    </div>
                `;
            }).join('');
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // Load dashboard on page load
        loadDashboard();
    </script>
<?php require __DIR__ . '/includes/footer.php'; ?>
