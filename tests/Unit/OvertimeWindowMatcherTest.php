<?php

namespace Tests\Unit;

use App\Services\OvertimeWindowMatcher;
use PHPUnit\Framework\TestCase;

class OvertimeWindowMatcherTest extends TestCase
{
    /** Segments for a 09:00-17:00 shift with the given check-in/out. */
    private function segments(string $in, string $out, string $start = '09:00', string $end = '17:00'): array
    {
        return OvertimeWindowMatcher::outsideShiftSegments(
            OvertimeWindowMatcher::timeToMinutes($in),
            OvertimeWindowMatcher::timeToMinutes($out),
            OvertimeWindowMatcher::timeToMinutes($start),
            OvertimeWindowMatcher::timeToMinutes($end),
        );
    }

    public function test_time_parsing(): void
    {
        $this->assertSame(540, OvertimeWindowMatcher::timeToMinutes('09:00'));
        $this->assertSame(540, OvertimeWindowMatcher::timeToMinutes('09:00:00'));
        $this->assertSame(0, OvertimeWindowMatcher::timeToMinutes('00:00'));
        $this->assertSame(1439, OvertimeWindowMatcher::timeToMinutes('23:59'));
        $this->assertNull(OvertimeWindowMatcher::timeToMinutes(null));
        $this->assertNull(OvertimeWindowMatcher::timeToMinutes(''));
        $this->assertNull(OvertimeWindowMatcher::timeToMinutes('abc'));
        $this->assertNull(OvertimeWindowMatcher::timeToMinutes('24:00'));
    }

    public function test_full_window_coverage_earns_full_requested_duration(): void
    {
        // Requested 08:00-09:00 (one hour before work), fingerprint 07:30 in.
        $segments = $this->segments('07:30', '17:00');
        $this->assertSame(60, OvertimeWindowMatcher::authorizedMinutes($segments, 480, 540, 60));
    }

    public function test_partial_overlap_earns_partial_duration(): void
    {
        // Amer's confirmed rule: request says one hour, employee stayed half
        // an hour of it, half an hour counts. Fingerprint 08:30 in.
        $segments = $this->segments('08:30', '17:00');
        $this->assertSame(30, OvertimeWindowMatcher::authorizedMinutes($segments, 480, 540, 60));
    }

    public function test_post_work_presence_does_not_cover_a_pre_work_window(): void
    {
        // Stayed two hours late but never came early: pre-work window earns 0.
        $segments = $this->segments('09:00', '19:00');
        $this->assertSame(0, OvertimeWindowMatcher::authorizedMinutes($segments, 480, 540, 60));
    }

    public function test_pre_work_presence_does_not_cover_a_post_work_window(): void
    {
        $segments = $this->segments('07:00', '17:00');
        $this->assertSame(0, OvertimeWindowMatcher::authorizedMinutes($segments, 1020, 1140, 60));
    }

    public function test_post_work_window_partial(): void
    {
        // Window 17:00-19:00, fingerprint out at 18:00: one hour earned.
        $segments = $this->segments('09:00', '18:00');
        $this->assertSame(60, OvertimeWindowMatcher::authorizedMinutes($segments, 1020, 1140, 120));
    }

    public function test_earned_minutes_are_capped_at_requested_duration(): void
    {
        // Window 17:00-19:00 fully covered, but only one hour requested.
        $segments = $this->segments('09:00', '19:30');
        $this->assertSame(60, OvertimeWindowMatcher::authorizedMinutes($segments, 1020, 1140, 60));
    }

    public function test_overnight_window_written_across_midnight(): void
    {
        // Window 22:00-01:00, fingerprint out at 23:30: 90 minutes.
        $segments = $this->segments('09:00', '23:30');
        $this->assertSame(90, OvertimeWindowMatcher::authorizedMinutes($segments, 1320, 60, 120));
    }

    public function test_after_midnight_window_written_as_early_morning(): void
    {
        // Window 00:30-02:00 with check-out 02:00 next day: 90 minutes via
        // the shifted placement.
        $segments = $this->segments('09:00', '02:00');
        $this->assertSame(90, OvertimeWindowMatcher::authorizedMinutes($segments, 30, 120, 120));
    }

    public function test_overnight_shift(): void
    {
        // Shift 22:00-06:00, in 21:00 out 07:00: one hour before and one hour
        // after are outside the shift. Window 21:00-22:00 earns 60.
        $segments = $this->segments('21:00', '07:00', '22:00', '06:00');
        $this->assertSame(60, OvertimeWindowMatcher::authorizedMinutes($segments, 1260, 1320, 60));
    }

    public function test_no_outside_shift_presence_earns_nothing(): void
    {
        $segments = $this->segments('09:00', '17:00');
        $this->assertSame([], $segments);
        $this->assertSame(0, OvertimeWindowMatcher::authorizedMinutes($segments, 480, 540, 60));
    }

    public function test_zero_requested_minutes_earn_nothing(): void
    {
        $segments = $this->segments('07:30', '17:00');
        $this->assertSame(0, OvertimeWindowMatcher::authorizedMinutes($segments, 480, 540, 0));
    }

    public function test_quarter_hour_precision(): void
    {
        // In 08:15, window 08:00-09:00, requested one hour: 45 minutes.
        $segments = $this->segments('08:15', '17:00');
        $this->assertSame(45, OvertimeWindowMatcher::authorizedMinutes($segments, 480, 540, 60));
    }
}
