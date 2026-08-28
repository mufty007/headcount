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

    public function testPublicDiscountPercentLabel(): void
    {
        $out = CouponService::publicDiscount(['code' => 'save20', 'percent_off' => 20]);
        $this->assertSame('SAVE20', $out['code']);
        $this->assertSame(20.0, $out['percent_off']);
        $this->assertNull($out['amount_off']);
        $this->assertSame('20% off', $out['label']);
    }

    public function testPublicDiscountAmountLabel(): void
    {
        $out = CouponService::publicDiscount(['code' => 'FIVE', 'amount_off' => 5]);
        $this->assertSame(5.0, $out['amount_off']);
        $this->assertNull($out['percent_off']);
        $this->assertSame('$5.00 off', $out['label']);
    }
}
