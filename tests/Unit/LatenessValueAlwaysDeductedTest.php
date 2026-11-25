<?php

namespace Tests\Unit;

use App\Models\Employee;
use App\Services\AttendancePenaltyPolicyService;
use PHPUnit\Framework\TestCase;

class LatenessValueAlwaysDeductedTest extends TestCase
{
    public function test_lost_time_minutes_counted_without_a_penalty_policy(): void
    {
        $employee = new Employee();
        $days = [
            ['date' => '2026-11-02', 'late_minutes' => 20, 'early_leave_minutes' => 0],
            ['date' => '2026-11-03', 'late_minutes' => 0, 'early_leave_minutes' => 15],
            ['date' => '2026-11-04', 'late_minutes' => 10, 'early_leave_minutes' => 5, 'state' => 'future'],
            ['date' => '2025-12-01', 'late_minutes' => 30, 'early_leave_minutes' => 0, 'state' => 'before_hire'],
        ];
        $result = (new AttendancePenaltyPolicyService())->evaluate($employee, $days);

        $this->assertSame(35, $result['time_minutes']);
        $this->assertSame(0.0, $result['days']);
        $this->assertSame([], $result['details']);
    }
}
