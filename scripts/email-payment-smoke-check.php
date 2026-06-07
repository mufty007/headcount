<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Services\EmailService;

$config = require __DIR__ . '/../config/config.php';
Database::getInstance($config['database']);
$db = Database::getInstance();

$orgId = isset($argv[1]) ? (int) $argv[1] : 1;
$org = $db->queryOne(
    "SELECT id, name, smtp_from_email FROM organizations WHERE id = ?",
    [$orgId]
);

if (!$org) {
    fwrite(STDERR, "Organization not found: {$orgId}\n");
    exit(1);
}

$emailConfig = [
    'api_key' => 'smoke-test-key',
    'from_email' => $org['smtp_from_email'] ?: 'no-reply@example.com',
    'from_name' => $org['name'] ?? 'Headcount',
];

$svc = new EmailService($emailConfig);
$template = "Hi {name}, {event_name} is on {event_day}, {event_date} at {event_time} in {event_location}.";
$merged = $svc->processTemplate($template, [
    'first_name' => 'Amina',
    'last_name' => 'Rahman',
    'event_name' => 'Mother and Daughter Brunch',
    'event_date' => '2026-04-28',
    'event_time' => '10:00 AM',
    'event_location' => 'MTI School of Knowledge',
]);

echo "Email merge smoke check:\n";
echo $merged . "\n\n";

$stripeRows = $db->query(
    "SELECT id, stripe_secret_key_encrypted, stripe_webhook_secret_encrypted FROM organizations WHERE id = ?",
    [$orgId]
);
$hasStripeSecret = !empty($stripeRows[0]['stripe_secret_key_encrypted']);
$hasWebhookSecret = !empty($stripeRows[0]['stripe_webhook_secret_encrypted']);

echo "Stripe config smoke check:\n";
echo "- Organization: " . (int) $orgId . "\n";
echo "- Secret key configured: " . ($hasStripeSecret ? 'yes' : 'no') . "\n";
echo "- Webhook secret configured: " . ($hasWebhookSecret ? 'yes' : 'no') . "\n";

if (!$hasStripeSecret || !$hasWebhookSecret) {
    echo "WARNING: Missing Stripe config may cause delayed payment finalization.\n";
}

exit(0);
