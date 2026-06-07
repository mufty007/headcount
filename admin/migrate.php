<?php

/**
 * Database Migration Runner
 * Run pending database migrations
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Headcount\Middleware\AuthMiddleware;
use Headcount\Helpers\Database;
use Headcount\Database\Migration;

// Require admin authentication
AuthMiddleware::requireAdmin();

// Load configuration
$config = require __DIR__ . '/../config/config.php';

// Initialize database
$db = Database::getInstance($config['database']);

// Get PDO instance for migration
$pdo = $db->getConnection();

// Initialize migration system
$migration = new Migration($pdo);

$message = '';
$error = '';
$executed = [];

// Handle migration request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_migrations'])) {
    try {
        $result = $migration->run();
        if ($result['success']) {
            $message = 'Migrations completed successfully!';
            $executed = $result['executed'];
        } else {
            $error = 'Some migrations failed. Check errors below.';
        }
    } catch (Exception $e) {
        $error = 'Migration error: ' . $e->getMessage();
    }
}

// Get migration status
$status = $migration->getStatus();
$pending = $migration->getPendingMigrations();

// If JSON request
if (isset($_GET['json'])) {
    header('Content-Type: application/json');
    echo json_encode([
        'status' => $status,
        'pending' => $pending
    ]);
    exit;
}

// HTML output
include __DIR__ . '/includes/header.php';
?>

<div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold mb-6">Database Migrations</h1>

    <?php if ($message): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded mb-4">
            <?php echo htmlspecialchars($message); ?>
            <?php if (!empty($executed)): ?>
                <ul class="mt-2 list-disc list-inside">
                    <?php foreach ($executed as $mig): ?>
                        <li><?php echo htmlspecialchars($mig); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-xl font-semibold mb-4">Migration Status</h2>
        
        <?php if (empty($pending)): ?>
            <p class="text-green-600">All migrations are up to date!</p>
        <?php else: ?>
            <p class="text-yellow-600 mb-4"><?php echo count($pending); ?> pending migration(s)</p>
            <form method="POST">
                <button type="submit" name="run_migrations" class="bg-indigo-600 text-white px-4 py-2 rounded hover:bg-indigo-700">
                    Run Pending Migrations
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
        <h2 class="text-xl font-semibold mb-4">All Migrations</h2>
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Migration</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Executed At</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($status as $mig): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                            <?php echo htmlspecialchars($mig['migration']); ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <?php if ($mig['executed']): ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">
                                    Executed
                                </span>
                            <?php else: ?>
                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">
                                    Pending
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                            <?php echo $mig['executed_at'] ? htmlspecialchars($mig['executed_at']) : '-'; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
