<?php

namespace Headcount\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Headcount\Services\PortalEmailService;

/**
 * Welcome / RSVP email body placeholder tests (no DB).
 */
class PortalEmailBodyTest extends TestCase
{
    public function testBuildRsvpConfirmationInjectsEventAndDashboardUrls(): void
    {
        $service = new PortalEmailService([
            'api_key' => 'test-key',
            'from_email' => 'noreply@example.com',
            'from_name' => 'Test',
        ]);

        $event = [
            'id' => 99,
            'title' => 'Community Night',
            'event_date' => '2030-01-15',
            'start_time' => '18:00:00',
            'location' => 'Main Hall',
            'description' => '<script>alert(1)</script>Hello',
            'is_virtual' => 0,
        ];
        $member = [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@example.com',
        ];

        $body = $service->buildRSVPConfirmationBody($event, $member);

        $this->assertStringContainsString('Ada', $body);
        $this->assertStringContainsString('Community Night', $body);
        $this->assertStringContainsString('event-details.php?id=99', $body);
        $this->assertStringContainsString('my-rsvps.php', $body);
        $this->assertStringNotContainsString('<script>', $body);
        $this->assertStringContainsString('Hello', $body);
    }
}
