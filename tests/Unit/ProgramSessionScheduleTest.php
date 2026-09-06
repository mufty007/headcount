<?php

namespace Headcount\Tests\Unit;

use DateTime;
use Headcount\Services\ProgramService;
use PHPUnit\Framework\TestCase;

class ProgramSessionScheduleTest extends TestCase
{
    public function testWeeklyDatesStartOnStartsOnAndSkipPriorWeekday(): void
    {
        $start = new DateTime('2026-09-12');
        $end = new DateTime('2026-10-03');
        $dates = ProgramService::enumerateRecurrenceDateStrings($start, $end, [6], 'weekly');
        $this->assertSame([
            '2026-09-12',
            '2026-09-19',
            '2026-09-26',
            '2026-10-03',
        ], $dates);
        $this->assertNotContains('2026-09-05', $dates);
    }

    public function testBiweeklySkipsEveryOtherWeek(): void
    {
        $start = new DateTime('2026-09-12');
        $end = new DateTime('2026-10-24');
        $dates = ProgramService::enumerateRecurrenceDateStrings($start, $end, [6], 'biweekly');
        $this->assertSame([
            '2026-09-12',
            '2026-09-26',
            '2026-10-10',
            '2026-10-24',
        ], $dates);
        $this->assertNotContains('2026-09-19', $dates);
    }
}
