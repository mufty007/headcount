<?php
/**
 * Public unsubscribe handler for email campaigns (CAN-SPAM).
 * GET params: org (organization id), email, token (signed).
 */
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/src/helpers.php';

use Headcount\Helpers\Database;

$config = require BASE_PATH . '/config/config.php';
$signingKey = $config['security']['encryption_key'] ?? null;
$appUrl = rtrim($config['app']['url'] ?? '', '/');

$orgId = isset($_GET['org']) ? (int) $_GET['org'] : 0;
$email = isset($_GET['email']) ? trim((string) $_GET['email']) : '';
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';

$error = '';
$success = false;

if ($orgId < 1 || $email === '' || $token === '') {
    $error = 'Invalid link. Please use the unsubscribe link from the email you received.';
} elseif (!verifyUnsubscribeToken($orgId, $email, $token, $signingKey)) {
    $error = 'Invalid or expired link. Please use the unsubscribe link from the email you received.';
} else {
    try {
        $db = Database::getInstance($config['database']);
        $tableExists = $db->query("SHOW TABLES LIKE 'email_unsubscribes'");
        if (empty($tableExists)) {
            $error = 'Unsubscribe is not available at this time.';
        } else {
            $db->query(
                "INSERT INTO email_unsubscribes (organization_id, email, campaign_id, unsubscribed_at) VALUES (?, ?, NULL, NOW()) ON DUPLICATE KEY UPDATE unsubscribed_at = NOW()",
                [$orgId, $email]
            );
            $success = true;
        }
    } catch (\Throwable $e) {
        error_log("Unsubscribe error: " . $e->getMessage());
        $error = 'We could not process your request. Please try again later.';
    }
}

$pageTitle = $success ? 'You’re unsubscribed' : 'Unsubscribe';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-xl shadow-sm border border-gray-200 p-8 text-center">
        <?php if ($success): ?>
            <div class="w-14 h-14 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg class="w-8 h-8 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            </div>
            <h1 class="text-xl font-bold text-gray-900 mb-2">You’re unsubscribed</h1>
            <p class="text-gray-600 text-sm">You will no longer receive marketing emails from this organization at <strong><?php echo htmlspecialchars($email); ?></strong>.</p>
        <?php else: ?>
            <div class="w-14 h-14 bg-amber-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <svg class="w-8 h-8 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </div>
            <h1 class="text-xl font-bold text-gray-900 mb-2">Unsubscribe</h1>
            <p class="text-gray-600 text-sm"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>
    </div>
</body>
</html>
