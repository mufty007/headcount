<?php

/**
 * CLI Database Migration Runner
 *
 * Usage:
 *   php cli_migrate.php
 *
 * Bootstraps config, runs pending SQL migrations under database/migrations/,
 * and prints a summary. Prefer this (or Admin → Migrate) over re-importing
 * schema.sql on existing databases.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "cli_migrate.php must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Database\Migration;

$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "Config not found: config/config.php\n");
    exit(1);
}

$config = require $configFile;

try {
    $db = Database::getInstance($config['database']);
    $pdo = $db->getConnection();
} catch (\Throwable $e) {
    fwrite(STDERR, 'Database connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$migration = new Migration($pdo);
$pending = $migration->getPendingMigrations();

if (empty($pending)) {
    echo "No pending migrations.\n";
    exit(0);
}

echo 'Pending migrations (' . count($pending) . "):\n";
foreach ($pending as $name) {
    echo "  - {$name}\n";
}

$result = $migration->run();

if (!empty($result['executed'])) {
    echo "\nExecuted:\n";
    foreach ($result['executed'] as $name) {
        echo "  ✓ {$name}\n";
    }
}

if (!empty($result['errors'])) {
    echo "\nErrors:\n";
    foreach ($result['errors'] as $err) {
        $name = $err['migration'] ?? 'unknown';
        $msg = $err['error'] ?? 'unknown error';
        echo "  ✗ {$name}: {$msg}\n";
    }
    exit(1);
}

echo "\nMigrations completed successfully.\n";
exit(0);
