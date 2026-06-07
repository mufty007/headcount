<?php

/**
 * Application Version Information
 */

define('APP_VERSION', '1.0.0');
define('APP_VERSION_DATE', '2024-01-01');
define('APP_VERSION_NAME', 'Initial Release');

/**
 * Get version information
 */
function getVersionInfo()
{
    return [
        'version' => APP_VERSION,
        'date' => APP_VERSION_DATE,
        'name' => APP_VERSION_NAME
    ];
}
