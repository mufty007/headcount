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
$memberId = PortalAuthMiddleware::getMemberId();

// Generate QR code data directly
use Headcount\Services\QRCodeService;

$qrService = new QRCodeService();
$qrData = $qrService->generateQRCodeData($memberId);

// Generate QR code image URL using Google Charts API
// This avoids dependency issues with the endroid/qr-code library
$qrCodeSvg = '';
$qrCodeDataUri = '';
$qrCodeDataForJS = '';
if ($qrData && !empty($qrData['full_code'])) {
    // Use Google Charts API - reliable and no dependencies required
    $encodedData = urlencode($qrData['full_code']);
    $qrCodeDataUri = 'https://chart.googleapis.com/chart?cht=qr&chs=300x300&chl=' . $encodedData;
    $qrCodeDataForJS = $qrData['full_code'];
}

// Calculate base URLs (for download functionality)
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
                <div id="qr-code-container" class="bg-white p-4 rounded-lg border-2 border-gray-200 min-h-[300px] flex items-center justify-center">
                    <?php if ($qrCodeDataUri): ?>
                        <img src="<?php echo htmlspecialchars($qrCodeDataUri); ?>" 
                             alt="QR Code" 
                             class="w-64 h-64 mx-auto"
                             id="qr-code-image"
                             onerror="this.style.display='none'; document.getElementById('qr-error-fallback').style.display='block';">
                        <div id="qr-error-fallback" class="text-red-500 text-center" style="display: none;">
                            <p>Failed to load QR code image</p>
                            <p class="text-sm mt-2">Please try refreshing the page.</p>
                        </div>
                    <?php else: ?>
                        <div class="text-red-500 text-center">
                            <p>Error generating QR code</p>
                            <p class="text-sm mt-2">Unable to generate QR code data.</p>
                        </div>
                    <?php endif; ?>
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
        const qrCodeData = <?php echo json_encode($qrCodeDataForJS); ?>;
        const qrCodeSvg = <?php echo json_encode($qrCodeSvg); ?>;
        const qrCodeDataUri = <?php echo json_encode($qrCodeDataUri); ?>;

        // Load QR code library with fallback
        let qrCodeLibraryLoaded = false;
        function loadQRCodeLibrary(callback) {
            if (typeof QRCode !== 'undefined') {
                qrCodeLibraryLoaded = true;
                if (callback) callback();
                return;
            }
            
            // Try multiple CDNs as fallback
            const cdnUrls = [
                'https://unpkg.com/qrcode@1.5.3/build/qrcode.min.js',
                'https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js',
                'https://cdnjs.cloudflare.com/ajax/libs/qrcode/1.5.3/qrcode.min.js'
            ];
            
            let currentIndex = 0;
            function tryLoadNext() {
                if (currentIndex >= cdnUrls.length) {
                    console.error('All QR code library CDNs failed to load');
                    // Use canvas API directly as last resort
                    generateQRCodeWithCanvas();
                    return;
                }
                
                const script = document.createElement('script');
                script.src = cdnUrls[currentIndex];
                script.onload = function() {
                    qrCodeLibraryLoaded = true;
                    console.log('QR code library loaded from:', cdnUrls[currentIndex]);
                    if (callback) callback();
                };
                script.onerror = function() {
                    console.warn('Failed to load QR code library from:', cdnUrls[currentIndex]);
                    currentIndex++;
                    tryLoadNext();
                };
                document.head.appendChild(script);
            }
            
            tryLoadNext();
        }

        // Simple QR code generator using QR.js algorithm (pure JavaScript, no dependencies)
        function generateQRCodePureJS(data) {
            if (!data) return null;
            
            // Use a simple QR code API that works via proxy or generate server-side
            // For now, we'll use a simple approach: create a proxy endpoint
            const container = document.getElementById('qr-code-container');
            const img = document.getElementById('qr-code-image');
            const errorDiv = document.getElementById('qr-error-fallback');
            
            if (img) img.style.display = 'none';
            if (errorDiv) errorDiv.style.display = 'none';
            
            // Create canvas for QR code
            const canvas = document.createElement('canvas');
            canvas.id = 'qr-code-canvas';
            canvas.className = 'w-64 h-64 mx-auto';
            canvas.width = 256;
            canvas.height = 256;
            
            const ctx = canvas.getContext('2d');
            
            // Use a simple QR code service that doesn't require CORS
            // We'll use api.qrserver.com which supports CORS
            const qrServiceUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=256x256&data=' + encodeURIComponent(data);
            
            const tempImg = new Image();
            tempImg.onload = function() {
                ctx.fillStyle = '#FFFFFF';
                ctx.fillRect(0, 0, 256, 256);
                ctx.drawImage(tempImg, 0, 0, 256, 256);
                container.innerHTML = '';
                container.appendChild(canvas);
                console.log('QR code generated successfully using api.qrserver.com');
            };
            tempImg.onerror = function() {
                console.error('Failed to load QR code from api.qrserver.com');
                // Try one more fallback - use the API endpoint
                generateQRCodeFromAPI();
            };
            tempImg.src = qrServiceUrl;
        }
        
        // Generate QR code from our own API endpoint
        function generateQRCodeFromAPI() {
            if (!qrCodeData) {
                showQRCodeError('QR code data not available');
                return;
            }
            
            const container = document.getElementById('qr-code-container');
            const img = document.getElementById('qr-code-image');
            const errorDiv = document.getElementById('qr-error-fallback');
            
            if (img) img.style.display = 'none';
            if (errorDiv) errorDiv.style.display = 'none';
            
            // Try to fetch from our API endpoint
            const apiUrl = <?php echo json_encode($apiBase); ?> + 'qr-code/image';
            
            fetch(apiUrl, {
                credentials: 'same-origin',
                headers: {
                    'Accept': 'image/svg+xml, image/*'
                }
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('API returned ' + response.status);
                }
                return response.blob();
            })
            .then(blob => {
                const objectUrl = URL.createObjectURL(blob);
                const canvas = document.createElement('canvas');
                canvas.id = 'qr-code-canvas';
                canvas.className = 'w-64 h-64 mx-auto';
                canvas.width = 256;
                canvas.height = 256;
                
                const ctx = canvas.getContext('2d');
                const tempImg = new Image();
                tempImg.onload = function() {
                    ctx.fillStyle = '#FFFFFF';
                    ctx.fillRect(0, 0, 256, 256);
                    ctx.drawImage(tempImg, 0, 0, 256, 256);
                    container.innerHTML = '';
                    container.appendChild(canvas);
                    URL.revokeObjectURL(objectUrl);
                    console.log('QR code loaded from API successfully');
                };
                tempImg.onerror = function() {
                    URL.revokeObjectURL(objectUrl);
                    showQRCodeError('Failed to generate QR code image');
                };
                tempImg.src = objectUrl;
            })
            .catch(error => {
                console.error('API fetch error:', error);
                // Last resort: show error message
                showQRCodeError('Unable to generate QR code. Please try refreshing the page.');
            });
        }
        
        // Main function to generate QR code - tries multiple methods
        function generateQRCodeFallback() {
            if (!qrCodeData) {
                showQRCodeError('QR code data not available');
                return;
            }
            
            console.log('Starting QR code generation fallback sequence');
            // Try api.qrserver.com first (supports CORS)
            generateQRCodePureJS(qrCodeData);
        }
        
        function showQRCodeError(message) {
            const errorDiv = document.getElementById('qr-error-fallback');
            if (errorDiv) {
                errorDiv.style.display = 'block';
                errorDiv.querySelector('p').textContent = message;
            }
        }
        
        // Generate QR code using Canvas API directly (no external library)
        function generateQRCodeWithCanvas() {
            if (!qrCodeData) {
                console.error('QR code data not available');
                showQRCodeError('QR code data not available');
                return;
            }
            
            // Use api.qrserver.com which supports CORS (first choice)
            console.log('Attempting to generate QR code using api.qrserver.com');
            generateQRCodePureJS(qrCodeData);
        }

        // Generate QR code using JavaScript library as fallback
        document.addEventListener('DOMContentLoaded', function() {
            const img = document.getElementById('qr-code-image');
            if (img && qrCodeData) {
                // Check if image loaded successfully
                img.onload = function() {
                    console.log('QR code image loaded from Google Charts');
                };
                
                // If image fails to load, generate QR code client-side
                img.onerror = function() {
                    console.log('Google Charts failed, trying alternative methods');
                    // Try api.qrserver.com first (supports CORS), then our API
                    generateQRCodeFallback();
                };
                
                // Also try to generate client-side after a short delay to ensure it works
                setTimeout(function() {
                    if (img.complete && img.naturalHeight === 0) {
                        // Image failed to load
                        console.log('Image still not loaded, trying alternative methods');
                        generateQRCodeFallback();
                    }
                }, 2000);
            }
        });

        function generateQRCodeClientSide() {
            // Try api.qrserver.com first, then our API
            generateQRCodeFallback();
        }

        async function downloadQRCode() {
            if (!qrCodeData) {
                alert('QR code not available');
                return;
            }
            
            try {
                let blob;
                let filename = 'my-qr-code.png';
                
                // Check if we have a canvas (client-side generated)
                const canvas = document.getElementById('qr-code-canvas');
                if (canvas) {
                    // Use canvas to download
                    canvas.toBlob(function(blob) {
                        if (blob) {
                            const url = URL.createObjectURL(blob);
                            const link = document.createElement('a');
                            link.href = url;
                            link.download = filename;
                            document.body.appendChild(link);
                            link.click();
                            document.body.removeChild(link);
                            URL.revokeObjectURL(url);
                        }
                    }, 'image/png');
                    return;
                }
                
                // Otherwise try to download from image or generate
                if (qrCodeSvg) {
                    // Use the SVG directly
                    blob = new Blob([qrCodeSvg], { type: 'image/svg+xml' });
                    filename = 'my-qr-code.svg';
                } else if (qrCodeDataUri) {
                    // Fetch from data URI or URL
                    if (qrCodeDataUri.startsWith('data:')) {
                        // Extract base64 data from data URI
                        const base64Data = qrCodeDataUri.split(',')[1];
                        const binaryData = atob(base64Data);
                        const bytes = new Uint8Array(binaryData.length);
                        for (let i = 0; i < binaryData.length; i++) {
                            bytes[i] = binaryData.charCodeAt(i);
                        }
                        blob = new Blob([bytes], { type: 'image/svg+xml' });
                        filename = 'my-qr-code.svg';
                    } else {
                        // Fetch from URL (Google Charts)
                        const response = await fetch(qrCodeDataUri);
                        if (!response.ok) {
                            throw new Error('Failed to fetch QR code');
                        }
                        blob = await response.blob();
                        filename = 'my-qr-code.png'; // Google Charts returns PNG
                    }
                } else {
                    // Try to use canvas if available, or generate with library
                    const canvas = document.getElementById('qr-code-canvas');
                    if (canvas) {
                        canvas.toBlob(function(blob) {
                            if (blob) {
                                const url = URL.createObjectURL(blob);
                                const link = document.createElement('a');
                                link.href = url;
                                link.download = filename;
                                document.body.appendChild(link);
                                link.click();
                                document.body.removeChild(link);
                                URL.revokeObjectURL(url);
                            }
                        }, 'image/png');
                        return;
                    } else if (typeof QRCode !== 'undefined') {
                        // Generate QR code as data URL and download
                        QRCode.toDataURL(qrCodeData, {
                            width: 512,
                            margin: 2
                        }, function (err, url) {
                            if (err) {
                                alert('Failed to generate QR code for download');
                                return;
                            }
                            // Convert data URL to blob
                            fetch(url).then(res => res.blob()).then(blob => {
                                const downloadUrl = URL.createObjectURL(blob);
                                const link = document.createElement('a');
                                link.href = downloadUrl;
                                link.download = filename;
                                document.body.appendChild(link);
                                link.click();
                                document.body.removeChild(link);
                                URL.revokeObjectURL(downloadUrl);
                            });
                        });
                        return;
                    } else {
                        throw new Error('No QR code data available');
                    }
                }
                
                // If we have a blob, download it
                if (blob) {
                    const url = URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = filename;
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    URL.revokeObjectURL(url);
                }
            } catch (error) {
                console.error('Error downloading QR code:', error);
                alert('Failed to download QR code. Please try again.');
            }
        }

        function printQRCode() {
            window.print();
        }
    </script>
    <style media="print">
        @media print {
            header, button { display: none; }
            body { background: white; }
        }
    </style>
<?php require __DIR__ . '/includes/footer.php'; ?>
