<?php

namespace Tests\Unit;

use App\Services\OvertimeGapReminderService as S;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class OvertimeGapReminderTest extends TestCase
{
    public function test_severity_turns_danger_in_the_last_four_days(): void
    {
        $end = Carbon::parse('2026-11-30');
        $this->assertSame('warning', S::severity($end, Carbon::parse('2026-11-25 23:59')));
        $this->assertSame('danger', S::severity($end, Carbon::parse('2026-11-26 00:00')));
        $this->assertSame('danger', S::severity($end, Carbon::parse('2026-11-30 12:00')));
        $this->assertSame('danger', S::severity($end, Carbon::parse('2026-12-05 12:00')));
    }

    public function test_repeat_cadence_escalates(): void
    {
        $end = Carbon::parse('2026-12-31');
        $latest = Carbon::parse('2026-11-01 08:00');
        $now = Carbon::parse('2026-11-02 09:00');
        // First repeat after a week, the next after two weeks, then every five days.
        $this->assertSame('2026-11-08', S::nextDueAt(1, $latest, $end, $now)->toDateString());
        $this->assertSame('2026-11-15', S::nextDueAt(2, $latest, $end, $now)->toDateString());
        $this->assertSame('2026-11-06', S::nextDueAt(3, $latest, $end, $now)->toDateString());
        $this->assertSame('2026-11-06', S::nextDueAt(10, $latest, $end, $now)->toDateString());
    }

    public function test_final_stretch_repeats_daily(): void
    {
        $end = Carbon::parse('2026-11-30');
        $latest = Carbon::parse('2026-11-20 08:00');
        // From the day before the period ends, repeats are due daily
        // regardless of where the escalation stood.
        $now = Carbon::parse('2026-11-29 09:00');
        $this->assertSame('2026-11-21', S::nextDueAt(1, $latest, $end, $now)->toDateString());
        $this->assertSame('2026-11-21', S::nextDueAt(6, $latest, $end, $now)->toDateString());
        // Earlier in the month the escalation still rules.
        $now = Carbon::parse('2026-11-28 09:00');
        $this->assertSame('2026-12-04', S::nextDueAt(2, $latest, $end, $now)->toDateString());
    }

    public function test_key_is_stable_per_employee_and_day(): void
    {
        $this->assertSame('overtime_gap:42:2026-11-15', S::key(42, '2026-11-15'));
    }
}
