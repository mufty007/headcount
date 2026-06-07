<?php

/**
 * Optional production URL overrides (gitignored copy: config/app.local.php).
 * Use on Hostinger when config.php still has localhost defaults from the repo.
 *
 * Copy to app.local.php and set your live URLs:
 *   cp config/app.local.example.php config/app.local.php
 */

return [
    // Full app base URL, e.g. https://events.example.org/Headcount
    'url' => 'https://events.example.org/Headcount',

    // Member portal base when different from url (optional)
    'portal_url' => 'https://events.example.org/Headcount',

    // Same as portal.public_base_url in config.php (optional)
    'public_base_url' => '',

    'environment' => 'production',
    'debug' => false,

    // Local XAMPP HTTP only: merge into config session.cookie_secure via config.php if needed
    // 'session' => ['cookie_secure' => false],
];
