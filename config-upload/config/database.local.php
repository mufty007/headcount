<?php
/**
 * Environment-aware database config (gitignored).
 *
 * Picks the correct database automatically so this same file can be FTP'd to
 * Hostinger WITHOUT repointing the live site at the local DB:
 *
 *   - Local Windows / XAMPP   -> local `headcount_dev`
 *   - Live Hostinger (Linux)  -> live  `u525556582_events`
 *
 * Detection: local dev runs on Windows (DIRECTORY_SEPARATOR is "\") or from an
 * XAMPP path; the Hostinger server is Linux ("/").
 */
$isLocal = DIRECTORY_SEPARATOR === '\\' || stripos(__DIR__, 'xampp') !== false;

if ($isLocal) {
    // Local XAMPP / MariaDB
    return [
        'host' => '127.0.0.1',
        'name' => 'headcount_dev',
        'username' => 'root',
        'password' => '',
    ];
}

// Live Hostinger
return [
    'host' => 'localhost',
    'name' => 'u525556582_events',
    'username' => 'u525556582_events',
    'password' => 'a3|R/9VEFT',
];
