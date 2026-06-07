<?php
/**
 * Cron Job: Generate Recurring Event Instances
 * 
 * This script should be run daily to generate upcoming recurring event instances
 * Recommended cron: 0 2 * * * (runs daily at 2 AM)
 * 
 * Usage: php cron/generate-recurring-events.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Headcount\Helpers\Database;
use Headcount\Services\RecurringEventService;

// Load config
$config = require __DIR__ . '/../config/config.php';

try {
    // Initialize database
    Database::getInstance($config['database']);
    
    // Generate instances up to 3 months in the future
    $recurringService = new RecurringEventService();
    $generatedCount = $recurringService->generateUpcomingInstances(new \DateTime('+3 months'));
    
    echo "Generated {$generatedCount} recurring event instance(s)\n";
    exit(0);
    
} catch (Exception $e) {
    error_log("Error generating recurring events: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
