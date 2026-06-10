<?php

/**
 * Portal entry bootstrap: defines HC_PROJECT_ROOT (directory containing vendor/, config/, src/).
 * Loaded first from scripts in this directory (public/portal/*.php).
 */
if (!defined('HC_PROJECT_ROOT')) {
    // Walk up the directory tree until vendor/autoload.php is found, so the app
    // loads whether this sits at public/portal/ or has been flattened into a
    // host's docroot (e.g. .../events/portal/). No fixed folder depth assumed.
    $hcDir = __DIR__;
    while ($hcDir !== dirname($hcDir) && !is_file($hcDir . '/vendor/autoload.php')) {
        $hcDir = dirname($hcDir);
    }
    define('HC_PROJECT_ROOT', $hcDir);
}
