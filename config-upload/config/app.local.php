<?php

/**
 * Production URL overrides for the live Hostinger deployment.
 * Subdomain docroot (events/) is served at https://events.imcaindy.org.
 */

return [
    'url' => 'https://events.imcaindy.org',
    'portal_url' => 'https://events.imcaindy.org',
    'public_base_url' => 'https://events.imcaindy.org',
    'environment' => 'production',
    'debug' => false,
];
