<?php

/**
 * System Health Check Page
 * Displays system status and health information
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Headcount\Middleware\AuthMiddleware;
use Headcount\Helpers\Database;

// Require admin authentication
AuthMiddleware::requireAdmin();

// Load configuration
$config = require __DIR__ . '/../../config/config.php';

function getMySQLVersion($db)
{
    try {
        $result = $db->query("SELECT VERSION() as version");
        $row = $result->fetch();
        return $row['version'] ?? 'Unknown';
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function getDiskSpace()
{
    $bytes = disk_free_space(__DIR__ . '/../../');
    $total = disk_total_space(__DIR__ . '/../../');
    return [
        'free' => round($bytes / 1024 / 1024 / 1024, 2) . ' GB',
        'total' => round($total / 1024 / 1024 / 1024, 2) . ' GB',
        'used' => round(($total - $bytes) / 1024 / 1024 / 1024, 2) . ' GB',
        'percent' => round((($total - $bytes) / $total) * 100, 2) . '%'
    ];
}

function getDatabaseSize($db)
{
    try {
        $config = require __DIR__ . '/../../config/config.php';
        $dbName = $config['database']['database'];
        $result = $db->query("SELECT 
            ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
            FROM information_schema.tables 
            WHERE table_schema = '{$dbName}'");
        $row = $result->fetch();
        return $row['size_mb'] ?? 0 . ' MB';
    } catch (Exception $e) {
        return 'Error: ' . $e->getMessage();
    }
}

function checkEmailConfig($config)
{
    return !empty($config['smtp2go']['api_key']);
}

function checkStripeConfig($config)
{
    return !empty($config['stripe']['secret_key']);
}

function getLastCronRun()
{
    $logFile = __DIR__ . '/../../logs/cron.log';
    if (file_exists($logFile)) {
        $lines = file($logFile);
        $lastLine = end($lines);
        return $lastLine ? trim($lastLine) : 'Never';
    }
    return 'Never';
}

function getErrorLogSize()
{
    $logFile = __DIR__ . '/../../logs/app.log';
    if (file_exists($logFile)) {
        $size = filesize($logFile);
        return round($size / 1024 / 1024, 2) . ' MB';
    }
    return '0 MB';
}

$db = Database::getInstance($config['database']);

$health = [
    'php_version' => PHP_VERSION,
    'mysql_version' => getMySQLVersion($db),
    'disk_space' => getDiskSpace(),
    'database_size' => getDatabaseSize($db),
    'email_config' => checkEmailConfig($config) ? 'Configured' : 'Not Configured',
    'stripe_config' => checkStripeConfig($config) ? 'Configured' : 'Not Configured',
    'last_cron_run' => getLastCronRun(),
    'error_log_size' => getErrorLogSize(),
];

// If JSON request
if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode($health);
    exit;
}

// Calculate base path if not set
if (!isset($basePath)) {
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/admin/', PHP_URL_PATH);
    $basePath = preg_replace('#/admin/.*$#', '', $requestPath);
    $basePath = rtrim($basePath, '/');
}
if (!isset($adminBase)) {
    $adminBase = $basePath . '/admin';
}

// HTML output
$pageTitle = 'System Health';
$currentPage = 'health';
require __DIR__ . '/includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <?php
    $pageHeaderTitle = 'System Health Check';
    $pageHeaderSubtitle = '';
    $pageHeaderActions = '';
    require __DIR__ . '/components/page-header.php';
    ?>

    <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-card">
        <h2 class="mb-4 text-xl font-semibold text-gray-900 dark:text-white">System Information</h2>
        
        <div class="grid grid-cols-1 gap-4 text-gray-700 dark:text-slate-300 md:grid-cols-2">
            <div>
                <strong>PHP Version:</strong> <?php echo htmlspecialchars($health['php_version']); ?>
            </div>
            <div>
                <strong>MySQL Version:</strong> <?php echo htmlspecialchars($health['mysql_version']); ?>
            </div>
            <div>
                <strong>Database Size:</strong> <?php echo htmlspecialchars($health['database_size']); ?>
            </div>
            <div>
                <strong>Error Log Size:</strong> <?php echo htmlspecialchars($health['error_log_size']); ?>
            </div>
        </div>
    </div>

    <div class="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-card">
        <h2 class="mb-4 text-xl font-semibold text-gray-900 dark:text-white">Disk Space</h2>
        <div class="grid grid-cols-1 gap-4 text-gray-700 dark:text-slate-300 md:grid-cols-4">
            <div>
                <strong>Total:</strong> <?php echo htmlspecialchars($health['disk_space']['total']); ?>
            </div>
            <div>
                <strong>Used:</strong> <?php echo htmlspecialchars($health['disk_space']['used']); ?>
            </div>
            <div>
                <strong>Free:</strong> <?php echo htmlspecialchars($health['disk_space']['free']); ?>
            </div>
            <div>
                <strong>Usage:</strong> <?php echo htmlspecialchars($health['disk_space']['percent']); ?>
            </div>
        </div>
    </div>

    <div class="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-card">
        <h2 class="mb-4 text-xl font-semibold text-gray-900 dark:text-white">Configuration Status</h2>
        <div class="grid grid-cols-1 gap-4 text-gray-700 dark:text-slate-300 md:grid-cols-2">
            <div>
                <strong>Email Service:</strong> 
                <span class="<?php echo $health['email_config'] === 'Configured' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'; ?>">
                    <?php echo htmlspecialchars($health['email_config']); ?>
                </span>
            </div>
            <div>
                <strong>Stripe Payment:</strong> 
                <span class="<?php echo $health['stripe_config'] === 'Configured' ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'; ?>">
                    <?php echo htmlspecialchars($health['stripe_config']); ?>
                </span>
            </div>
        </div>
    </div>

    <div class="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-card">
        <h2 class="mb-4 text-xl font-semibold text-gray-900 dark:text-white">Cron Jobs</h2>
        <div class="text-gray-700 dark:text-slate-300">
            <strong>Last Cron Run:</strong> <?php echo htmlspecialchars($health['last_cron_run']); ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
