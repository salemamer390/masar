<?php

namespace Tests\Unit;

use App\Services\InsuranceDeregistrationService as S;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class InsuranceDeregistrationTest extends TestCase
{
    public function test_levels_and_days_left(): void
    {
        $today = Carbon::parse('2026-11-25');
        $a = S::info('2026-11-25', $today);
        $this->assertSame([0, 7, 'warning', false], [$a['days'], $a['left'], $a['level'], $a['overdue']]);
        $b = S::info('2026-11-20', $today);
        $this->assertSame([5, 2, 'danger', false], [$b['days'], $b['left'], $b['level'], $b['overdue']]);
        $c = S::info('2026-11-17', $today);
        $this->assertSame([8, -1, 'danger', true], [$c['days'], $c['left'], $c['level'], $c['overdue']]);
        $this->assertSame(0, S::info('2026-11-30', $today)['days']);
    }

    public function test_reminder_cadence(): void
    {
        $this->assertSame('2026-11-25', S::reminderKey('warning', Carbon::parse('2026-11-25 15:00')));
        $this->assertNotSame(S::reminderKey('danger', Carbon::parse('2026-11-25 05:00')), S::reminderKey('danger', Carbon::parse('2026-11-25 07:00')));
        $this->assertSame(S::reminderKey('danger', Carbon::parse('2026-11-25 07:00')), S::reminderKey('danger', Carbon::parse('2026-11-25 11:59')));
    }
}
