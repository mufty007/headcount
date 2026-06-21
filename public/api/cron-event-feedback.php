<?php
/**
 * HTTP entry: post-event feedback emails (checked-in attendees, 1 day after event).
 *
 * GET .../public/api/cron-event-feedback.php?key=YOUR_SECRET
 * Or: .../public/api/cron-run.php?job=event-feedback&key=YOUR_SECRET
 */
require_once __DIR__ . '/includes/cron-http-bootstrap.php';

$output = headcount_cron_run_script(HC_PROJECT_ROOT . '/cron/send-event-feedback.php');
jsonResponse(['success' => true, 'job' => 'event-feedback', 'output' => $output]);
