<?php

namespace Headcount\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * URL helper tests — ensure config-based bases work without HTTP_HOST (cron-safe).
 * Extends PHPUnit TestCase directly (no DB).
 */
class UrlHelpersTest extends TestCase
{
    public function testPortalBaseUrlUsesConfiguredPortalUrlWithoutHttpHost(): void
    {
        $savedHost = $_SERVER['HTTP_HOST'] ?? null;
        unset($_SERVER['HTTP_HOST']);

        $config = [
            'app' => [
                'url' => 'https://admin.example.org',
                'portal_url' => 'https://events.example.org',
            ],
        ];

        $this->assertSame('https://events.example.org', headcount_portal_base_url($config));
        $this->assertSame(
            'https://events.example.org/portal/event-details.php?id=42',
            headcount_event_portal_url($config, 42)
        );
        $this->assertSame('https://admin.example.org', headcount_app_base_url($config));

        if ($savedHost !== null) {
            $_SERVER['HTTP_HOST'] = $savedHost;
        } else {
            unset($_SERVER['HTTP_HOST']);
        }
    }

    public function testPortalBaseUrlFallsBackToAppUrl(): void
    {
        $savedHost = $_SERVER['HTTP_HOST'] ?? null;
        unset($_SERVER['HTTP_HOST']);

        $config = [
            'app' => [
                'url' => 'https://app.example.org/Headcount',
            ],
        ];

        $this->assertSame('https://app.example.org/Headcount', headcount_portal_base_url($config));

        if ($savedHost !== null) {
            $_SERVER['HTTP_HOST'] = $savedHost;
        } else {
            unset($_SERVER['HTTP_HOST']);
        }
    }

    public function testWelcomeTemplateContainsBrowseEventsPlaceholder(): void
    {
        $path = dirname(__DIR__, 2) . '/templates/portal/welcome.html';
        $this->assertFileExists($path);
        $html = file_get_contents($path);
        $this->assertStringContainsString('{browse_events_url}', $html);
        $this->assertStringNotContainsString('href="#"', $html);

        $browseUrl = 'https://events.example.org/portal/events.php';
        $filled = str_replace('{browse_events_url}', $browseUrl, $html);
        $this->assertStringContainsString('href="' . $browseUrl . '"', $filled);
    }

    public function testRsvpConfirmationTemplateHasDashboardAndEventUrls(): void
    {
        $path = dirname(__DIR__, 2) . '/templates/portal/rsvp-confirmation.html';
        $this->assertFileExists($path);
        $html = file_get_contents($path);
        $this->assertStringContainsString('{event_url}', $html);
        $this->assertStringContainsString('{dashboard_url}', $html);
    }
}
