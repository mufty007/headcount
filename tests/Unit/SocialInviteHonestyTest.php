<?php

namespace Headcount\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Social invite API honesty checks (no DB).
 */
class SocialInviteHonestyTest extends TestCase
{
    public function testSocialInviteEndpointDoesNotHardcodeFakeSuccess(): void
    {
        $path = dirname(__DIR__, 2) . '/public/api/portal/social.php';
        $this->assertFileExists($path);
        $src = file_get_contents($path);
        $this->assertStringNotContainsString('// TODO: Send invitation emails', $src);
        $this->assertStringContainsString('createSocialInviteEmailService', $src);
        $this->assertStringContainsString('emails_failed', $src);
        $this->assertStringContainsString('No invitations were sent', $src);
    }

    public function testSocialInviteTemplateExists(): void
    {
        $path = dirname(__DIR__, 2) . '/templates/portal/social-invite.html';
        $this->assertFileExists($path);
        $html = file_get_contents($path);
        $this->assertStringContainsString('{event_url}', $html);
        $this->assertStringContainsString('{inviter_name}', $html);
    }
}
