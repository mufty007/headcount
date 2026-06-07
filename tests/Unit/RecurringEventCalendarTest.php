<?php

namespace Headcount\Tests\Unit;

use Headcount\Services\RecurringEventService;
use PHPUnit\Framework\TestCase;

class RecurringEventCalendarTest extends TestCase
{
    public function testIsValidStrictYmdAcceptsJune14(): void
    {
        $this->assertTrue(RecurringEventService::isValidStrictYmd('2026-06-14'));
    }

    public function testIsValidStrictYmdRejectsFakeMonth(): void
    {
        $this->assertFalse(RecurringEventService::isValidStrictYmd('2026-14-06'));
    }

    public function testEncodeResultRejectsBadLine(): void
    {
        $r = RecurringEventService::encodeCustomDatesFromInputResult(
            ['custom_session_dates' => ['2026-06-14', '2026-14-06']],
            '2026-06-13'
        );
        $this->assertNotEmpty($r['error'] ?? '');
        $this->assertStringContainsString('2026-14-06', (string) $r['error']);
    }

    public function testEncodeResultEncodesValid(): void
    {
        $r = RecurringEventService::encodeCustomDatesFromInputResult(
            ['custom_session_dates' => ['2026-06-14']],
            '2026-06-13'
        );
        $this->assertSame('', (string) ($r['error'] ?? ''));
        $this->assertSame('["2026-06-14"]', $r['json']);
    }

    public function testEncodeResultExcludesParentDate(): void
    {
        $r = RecurringEventService::encodeCustomDatesFromInputResult(
            ['custom_session_dates' => ['2026-06-13', '2026-06-14']],
            '2026-06-13'
        );
        $this->assertSame('["2026-06-14"]', $r['json']);
    }
}
