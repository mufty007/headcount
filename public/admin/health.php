<?php

/**
 * System Health Check Page
 * Displays system status and health information
 */

if (!defined('HC_PROJECT_ROOT')) {
    $hcRootDir = __DIR__;
    while ($hcRootDir !== dirname($hcRootDir) && !is_file($hcRootDir . '/vendor/autoload.php')) {
        $hcRootDir = dirname($hcRootDir);
    }
    define('HC_PROJECT_ROOT', $hcRootDir);
}
require_once HC_PROJECT_ROOT . '/vendor/autoload.php';

use Headcount\Middleware\AuthMiddleware;
use Headcount\Helpers\Database;

// Require admin authentication
AuthMiddleware::requireAdmin();

// Load configuration
$config = require HC_PROJECT_ROOT . '/config/config.php';

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
        'percent' => round((($total - $bytes) / $total) * 100, 2)
    ];
}

function getDatabaseSize($db)
{
    try {
        $config = require HC_PROJECT_ROOT . '/config/config.php';
        $dbName = $config['database']['database'];
        $result = $db->query("SELECT 
            ROUND(SUM(data_length + index_length) / 1024 / 1024, 2) AS size_mb
            FROM information_schema.tables 
            WHERE table_schema = '{$dbName}'");
        $row = $result->fetch();
        return ($row['size_mb'] ?? 0) . ' MB';
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

$diskSpace = getDiskSpace();
$emailConfigured = checkEmailConfig($config);
$stripeConfigured = checkStripeConfig($config);

$health = [
    'php_version' => PHP_VERSION,
    'mysql_version' => getMySQLVersion($db),
    'disk_space' => $diskSpace,
    'database_size' => getDatabaseSize($db),
    'email_config' => $emailConfigured ? 'Configured' : 'Not Configured',
    'stripe_config' => $stripeConfigured ? 'Configured' : 'Not Configured',
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

<div class="animate-fade-in">
    <?php
    $pageHeaderBreadcrumb = [
        ['label' => 'Dashboard', 'url' => $adminBase . '/?page=dashboard'],
        ['label' => 'System Health'],
    ];
    $pageHeaderTitle = 'System Health Check';
    $pageHeaderSubtitle = 'Runtime, storage, and integration status';
    $pageHeaderActions = '';
    require __DIR__ . '/components/page-header.php';
    ?>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 md:gap-6 xl:grid-cols-4">
        <?php
        $statLabel = 'PHP Version';
        $statValue = $health['php_version'];
        $statTrend = null;
        $statTrendLabel = 'Runtime';
        $statAccent = 'brand';
        $statIcon = 'layers';
        require __DIR__ . '/components/stat-card-trend.php';
        $statLabel = 'MySQL Version';
        $statValue = $health['mysql_version'];
        $statTrend = null;
        $statTrendLabel = 'Database server';
        $statAccent = 'sky';
        $statIcon = 'chart';
        require __DIR__ . '/components/stat-card-trend.php';
        $statLabel = 'Database Size';
        $statValue = $health['database_size'];
        $statTrend = null;
        $statTrendLabel = 'Schema footprint';
        $statAccent = 'warning';
        $statIcon = 'layers';
        require __DIR__ . '/components/stat-card-trend.php';
        $statLabel = 'Error Log Size';
        $statValue = $health['error_log_size'];
        $statTrend = null;
        $statTrendLabel = 'app.log';
        $statAccent = 'rose';
        $statIcon = 'mail';
        require __DIR__ . '/components/stat-card-trend.php';
        ?>
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <?php
        $progressListTitle = 'Disk Space';
        $progressListItems = [
            ['label' => 'Used', 'value' => $diskSpace['used'], 'percent' => $diskSpace['percent'], 'color' => 'brand'],
            ['label' => 'Free', 'value' => $diskSpace['free'], 'percent' => max(0, 100 - $diskSpace['percent']), 'color' => 'success'],
        ];
        require __DIR__ . '/components/progress-list.php';
        unset($progressListTitle, $progressListItems);

        $progressListTitle = 'Configuration Status';
        $progressListItems = [
            [
                'label' => 'Email Service (SMTP2GO)',
                'value' => $health['email_config'],
                'percent' => $emailConfigured ? 100 : 15,
                'color' => $emailConfigured ? 'success' : 'error',
            ],
            [
                'label' => 'Stripe Payment',
                'value' => $health['stripe_config'],
                'percent' => $stripeConfigured ? 100 : 15,
                'color' => $stripeConfigured ? 'success' : 'error',
            ],
        ];
        require __DIR__ . '/components/progress-list.php';
        unset($progressListTitle, $progressListItems);
        ?>
    </div>

    <div class="mt-6 rounded-2xl border border-gray-200 bg-white p-6 shadow-theme-sm dark:border-gray-800 dark:bg-white/[0.03]">
        <h2 class="mb-2 text-lg font-semibold text-gray-800 dark:text-white/90">Cron Jobs</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400">Last cron run</p>
        <p class="mt-2 font-mono text-sm text-gray-700 dark:text-gray-300"><?php echo htmlspecialchars($health['last_cron_run']); ?></p>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
