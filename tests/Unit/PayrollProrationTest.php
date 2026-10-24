<?php

namespace Tests\Unit;

use App\Services\PayrollProration;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class PayrollProrationTest extends TestCase
{
    private function days(string $start, string $end): int
    {
        return PayrollProration::paidDays(Carbon::parse($start)->startOfDay(), Carbon::parse($end)->endOfDay());
    }

    public function test_full_months_are_always_30_days(): void
    {
        $this->assertSame(30, $this->days('2026-10-01', '2026-10-31')); // 31-day month
        $this->assertSame(30, $this->days('2026-09-01', '2026-09-30')); // 30-day month
        $this->assertSame(30, $this->days('2027-02-01', '2027-02-28')); // February is a full month too
        $this->assertSame(30, $this->days('2028-02-01', '2028-02-29')); // leap year
    }

    public function test_hire_in_the_middle_of_the_month(): void
    {
        $this->assertSame(21, $this->days('2026-10-11', '2026-10-31'));
        $this->assertSame(15, $this->days('2026-09-16', '2026-09-30'));
        $this->assertSame(16, $this->days('2027-02-15', '2027-02-28')); // February is topped up to the 30-day basis
        $this->assertSame(30, $this->days('2026-10-02', '2026-10-31')); // capped at the basis
    }

    public function test_to_date_and_termination_periods(): void
    {
        $this->assertSame(22, $this->days('2026-10-01', '2026-10-22'));
        $this->assertSame(11, $this->days('2026-10-01', '2026-10-11'));
        $this->assertSame(1, $this->days('2026-10-01', '2026-10-01'));
    }

    public function test_factor_is_never_above_one_and_has_no_float_noise(): void
    {
        $this->assertSame(1.0, PayrollProration::factor(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')->endOfDay()));
        $this->assertEqualsWithDelta(22 / 30, PayrollProration::factor(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-22')), 0.0000001);
    }
}
