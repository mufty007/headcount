<?php
/**
 * Admin Directory Router
 * This file exists so Apache can find /headcount/admin/
 * It then routes to the actual admin router in public/admin/
 */

// Route to the public admin router
require __DIR__ . '/../public/admin/index.php';
