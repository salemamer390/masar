<?php

namespace Tests\Unit;

use App\Http\Controllers\PayrollController;
use PHPUnit\Framework\TestCase;

class TimeDeductionLinesTest extends TestCase
{
    private function lines($snapshot, float $combined): array
    {
        $m = new \ReflectionMethod(PayrollController::class, 'timeDeductionAmounts');
        $m->setAccessible(true);
        $controller = (new \ReflectionClass(PayrollController::class))->newInstanceWithoutConstructor();

        return $m->invoke($controller, $snapshot, $combined);
    }

    public function test_only_non_zero_lines_show(): void
    {
        $this->assertCount(2, $this->lines(['attendance_late_deduction' => 28.33, 'attendance_early_deduction' => 10.0], 38.33));
        $this->assertCount(1, $this->lines(['attendance_late_deduction' => 28.33, 'attendance_early_deduction' => 0], 28.33));
        $this->assertCount(1, $this->lines(['attendance_late_deduction' => 0, 'attendance_early_deduction' => 12.5], 12.5));
        $this->assertCount(0, $this->lines(['attendance_late_deduction' => 0, 'attendance_early_deduction' => 0], 0));
    }

    public function test_old_snapshot_shows_combined_amount_as_one_line(): void
    {
        $this->assertCount(1, $this->lines([], 28.33));
        $this->assertCount(0, $this->lines([], 0));
    }
}
