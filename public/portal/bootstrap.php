<?php

/**
 * Portal entry bootstrap: defines HC_PROJECT_ROOT (directory containing vendor/, config/, src/).
 * Loaded first from scripts in this directory (public/portal/*.php).
 */
if (!defined('HC_PROJECT_ROOT')) {
    define('HC_PROJECT_ROOT', dirname(__DIR__, 2));
}
