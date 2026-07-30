<?php

/**
 * Legacy reminders cron entry point.
 * Delegates to portal-reminders.php (the single supported reminder job).
 *
 * Prefer scheduling: cron/portal-reminders.php
 * Or HTTP: cron-run.php?job=portal-reminders
 */

require __DIR__ . '/portal-reminders.php';
