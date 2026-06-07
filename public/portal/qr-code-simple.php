<?php
/**
 * QR Code Display Page - Simplified Version
 * Shows member's QR code for check-in
 */

// Enable error display temporarily
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/bootstrap.php';
    require_once HC_PROJECT_ROOT . '/vendor/autoload.php';
} catch (\Throwable $e) {
    die("Autoload failed: " . $e->getMessage());
}

use Headcount\Middleware\PortalAuthMiddleware;
use Headcount\Helpers\Database;
use Headcount\Helpers\Security;

try {
    // Require authentication
    PortalAuthMiddleware::requireAuth();
} catch (\Throwable $e) {
    die("Auth failed: " . $e->getMessage());
}

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
    die("System initialization failed: " . $e->getMessage());
}

$member = PortalAuthMiddleware::getMember();

// Calculate base URLs
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/portal/', PHP_URL_PATH);
$baseUrlPath = preg_replace('#/portal/.*$#', '', $requestPath);
$baseUrlPath = rtrim($baseUrlPath, '/');
$apiBase = $baseUrlPath . '/api/portal/';

// Set page title
$pageTitle = 'My QR Code';

// Include header
require __DIR__ . '/includes/header.php';
?>

<div class="mb-8">
    <h1 class="text-3xl font-extrabold text-gray-900 tracking-tight">My QR Code</h1>
    <p class="text-gray-500 mt-1">Use this QR code for event check-in</p>
</div>

<div class="max-w-2xl">
        <div class="bento-card p-8 text-center">
            <h2 class="text-2xl font-bold text-gray-900 mb-4">Check-In QR Code</h2>
            <p class="text-gray-600 mb-6">Show this QR code at event check-in for fast, contactless entry.</p>
            
            <!-- QR Code Display -->
            <div class="flex justify-center mb-6">
                <div id="qr-code-container" class="bg-white p-4 rounded-lg border-2 border-gray-200">
                    <div class="text-gray-500">Loading QR code...</div>
                </div>
            </div>
            
            <!-- Instructions -->
            <div class="bg-blue-50 rounded-lg p-6 mb-6 text-left">
                <h3 class="font-semibold text-gray-900 mb-2">How to use:</h3>
                <ol class="list-decimal list-inside space-y-2 text-sm text-gray-700">
                    <li>Arrive at the event check-in area</li>
                    <li>Open this page on your phone or print this QR code</li>
                    <li>Show the QR code to the event staff</li>
                    <li>Staff will scan it for instant check-in</li>
                </ol>
            </div>
            
            <!-- Actions -->
            <div class="flex flex-wrap justify-center gap-4">
                <button onclick="downloadQRCode()" 
                        class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700">
                    Download QR Code
                </button>
                <button onclick="printQRCode()" 
                        class="px-6 py-2 bg-gray-200 text-gray-800 rounded-lg hover:bg-gray-300">
                    Print QR Code
                </button>
            </div>
        </div>
    </div>

    <script>
        const apiBase = <?php echo json_encode($apiBase); ?>;
        const baseUrl = <?php echo json_encode($baseUrlPath); ?>;

        // Load QR code
        async function loadQRCode() {
            try {
                const response = await fetch(apiBase + 'qr-code');
                const data = await response.json();

                if (data.success && data.qr_code) {
                    // Display QR code image
                    const qrImageUrl = apiBase + 'qr-code/image';
                    document.getElementById('qr-code-container').innerHTML = 
                        `<img src="${qrImageUrl}" alt="QR Code" class="w-64 h-64 mx-auto">`;
                } else {
                    document.getElementById('qr-code-container').innerHTML = 
                        '<div class="text-red-500">Error loading QR code</div>';
                }
            } catch (error) {
                console.error('Error:', error);
                document.getElementById('qr-code-container').innerHTML = 
                    '<div class="text-red-500">Error loading QR code</div>';
            }
        }

        function downloadQRCode() {
            const qrImageUrl = apiBase + 'qr-code/image';
            const link = document.createElement('a');
            link.href = qrImageUrl;
            link.download = 'my-qr-code.png';
            link.click();
        }

        function printQRCode() {
            window.print();
        }

        // Load QR code on page load
        loadQRCode();
    </script>
    <style media="print">
        @media print {
            header, button { display: none; }
            body { background: white; }
        }
    </style>
<?php require __DIR__ . '/includes/footer.php'; ?>
