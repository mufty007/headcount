<?php
/**
 * Path Debug Script
 * This will show us what paths are being calculated
 */

// Calculate base URLs (same logic as event-details.php)
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

// Test the buildCssPath function
function buildCssPath($basePath, $filename) {
    if (empty($basePath) || $basePath === '/') {
        $cssPath = '/public/css/' . $filename;
    } else {
        // Normalize basePath
        $cssPath = ($basePath[0] !== '/') ? '/' . $basePath : $basePath;
        $cssPath = rtrim($cssPath, '/');
        
        // Check if basePath already ends with /public
        if (substr($cssPath, -7) === '/public') {
            // Base path already includes /public, just add /css/
            $cssPath .= '/css/' . $filename;
        } else {
            // Base path doesn't include /public, add it
            $cssPath .= '/public/css/' . $filename;
        }
    }
    $cssPath = preg_replace('#/+#', '/', $cssPath);
    if ($cssPath[0] !== '/') {
        $cssPath = '/' . $cssPath;
    }
    return $cssPath;
}

header('Content-Type: text/plain');
echo "=== PATH DEBUG INFO ===\n\n";
echo "REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'not set') . "\n";
echo "SCRIPT_NAME: " . ($_SERVER['SCRIPT_NAME'] ?? 'not set') . "\n";
echo "DOCUMENT_ROOT: " . ($_SERVER['DOCUMENT_ROOT'] ?? 'not set') . "\n";
echo "SCRIPT_FILENAME: " . ($_SERVER['SCRIPT_FILENAME'] ?? 'not set') . "\n\n";

echo "Calculated requestPath: $requestPath\n";
echo "Calculated baseUrlPath: '$baseUrlPath'\n";
echo "baseUrlPath ends with /public? " . (substr($baseUrlPath, -7) === '/public' ? 'YES' : 'NO') . "\n\n";

echo "=== GENERATED PATHS ===\n";
echo "CSS Path (modal.css): " . buildCssPath($baseUrlPath, 'modal.css') . "\n";
echo "CSS Path (modern-design.css): " . buildCssPath($baseUrlPath, 'modern-design.css') . "\n\n";

echo "=== EXPECTED PATHS ===\n";
echo "Should be: /public/css/modal.css\n";
echo "Should be: /public/css/modern-design.css\n";
