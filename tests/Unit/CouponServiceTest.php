<?php

namespace Headcount\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Headcount\Services\CouponService;

class CouponServiceTest extends TestCase
{
    public function testPercentOffReducesTotal(): void
    {
        $this->assertSame(80.0, CouponService::applyDiscount(100.0, ['percent_off' => 20]));
    }

    public function testAmountOffFloorsAtZero(): void
    {
        $this->assertSame(0.0, CouponService::applyDiscount(5.0, ['amount_off' => 10]));
    }

    public function testMissingCouponLeavesTotal(): void
    {
        $this->assertSame(42.5, CouponService::applyDiscount(42.5, null));
    }
}
