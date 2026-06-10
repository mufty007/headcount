<?php
// Checkout debug v2 - DELETE AFTER USE
header('Content-Type: text/plain');
ini_set('display_errors', 1);
error_reporting(E_ALL);

$config = null;
foreach ([__DIR__ . '/../../config/config.php', __DIR__ . '/../../../config/config.php'] as $p) {
    if (file_exists($p)) { $config = require $p; break; }
}

require_once dirname(dirname(__DIR__)) . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Helpers\Security;
use Headcount\Services\PortalPaymentService;

Database::getInstance($config['database']);
Security::configureSession();
if (session_status() === PHP_SESSION_NONE) session_start();

$dbName = $config['database']['name'] ?? $config['database']['database'] ?? '';
$pdo = new PDO(
    'mysql:host=' . $config['database']['host'] . ';dbname=' . $dbName,
    $config['database']['username'] ?? $config['database']['user'] ?? '',
    $config['database']['password'] ?? ''
);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Show event #7 ticket types on live DB
echo "=== event_ticket_types for event #7 ===\n";
try {
    $stmt = $pdo->query("SELECT * FROM event_ticket_types WHERE event_id = 7");
    $tts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Count: " . count($tts) . "\n";
    foreach ($tts as $tt) {
        echo "  TT #{$tt['id']}: '{$tt['name']}' = \${$tt['price']} (limit: " . ($tt['quantity_limit'] ?? 'none') . ")\n";
    }
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== payments table columns ===\n";
try {
    $stmt = $pdo->query("SHOW COLUMNS FROM payments");
    $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo implode(', ', $cols) . "\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== Test createCheckoutSession (event #7, single ticket price, no ticket types) ===\n";
// Get a real member
$stmt = $pdo->query("SELECT id FROM users WHERE role = 'member' LIMIT 1");
$memberRow = $stmt->fetch(PDO::FETCH_ASSOC);
$memberId = $memberRow['id'] ?? null;
echo "Member ID: " . ($memberId ?? 'null') . "\n";

if ($memberId) {
    $_SESSION['portal_member_id'] = $memberId;
    $svc = new PortalPaymentService();

    // Test 1: Single price (no tickets array) - simulates non-ticket-type checkout
    echo "\n--- Test A: no tickets array (uses ticket_price = \$5.00) ---\n";
    try {
        $result = $svc->createCheckoutSession(7, $memberId, 0, []);
        echo "Result success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
        echo "Message: " . ($result['message'] ?? 'none') . "\n";
        if (!empty($result['checkout_url'])) echo "checkout_url: " . substr($result['checkout_url'], 0, 60) . "...\n";
    } catch (\Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
        echo "In: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo $e->getTraceAsString() . "\n";
    }

    // Test 2: With a fake ticket type ID (simulates the frontend sending ticket_type_id)
    echo "\n--- Test B: with tickets array [{ticket_type_id:1, quantity:1}] ---\n";
    try {
        $result = $svc->createCheckoutSession(7, $memberId, 0, [['ticket_type_id' => 1, 'quantity' => 1]]);
        echo "Result success: " . ($result['success'] ? 'YES' : 'NO') . "\n";
        echo "Message: " . ($result['message'] ?? 'none') . "\n";
        if (!empty($result['checkout_url'])) echo "checkout_url: " . substr($result['checkout_url'], 0, 60) . "...\n";
    } catch (\Throwable $e) {
        echo "EXCEPTION: " . $e->getMessage() . "\n";
        echo "In: " . $e->getFile() . ":" . $e->getLine() . "\n";
        echo $e->getTraceAsString() . "\n";
    }
}

echo "\nDone.\n";
