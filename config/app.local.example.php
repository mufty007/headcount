<?php

/**
 * Optional production URL overrides (gitignored copy: config/app.local.php).
 * Use on Hostinger when config.php still has localhost defaults from the repo.
 *
 * Copy to app.local.php and set your live URLs:
 *   cp config/app.local.example.php config/app.local.php
 */

return [
    // Full app base URL — NO trailing slash and NO path segment unless the app is
    // genuinely served from a subfolder of the domain.
    //   Subdomain docroot points at .../public  ->  'https://events.example.org'
    //   App served from a /subfolder/           ->  'https://events.example.org/subfolder'
    // Do NOT leave a stray '/Headcount' here unless your live URLs actually contain it,
    // or every generated event/QR/portal link will be prefixed with it.
    'url' => 'https://events.example.org',

    // Member portal base when different from url (optional)
    'portal_url' => 'https://events.example.org',

    // Same as portal.public_base_url in config.php (optional)
    'public_base_url' => '',

    'environment' => 'production',
    'debug' => false,

    // Local XAMPP HTTP only: merge into config session.cookie_secure via config.php if needed
    // 'session' => ['cookie_secure' => false],
];
