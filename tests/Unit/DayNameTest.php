<?php

namespace Tests\Unit;

use App\Support\DayName;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class DayNameTest extends TestCase
{
    public function test_names_the_weekday_for_each_input_shape(): void
    {
        $this->assertSame('Friday', DayName::of(Carbon::parse('2026-11-27'), 'en'));
        $this->assertSame('Friday', DayName::of('2026-11-27', 'en'));
        $this->assertSame('Friday', DayName::of('27/11/2026', 'en'));
        $this->assertSame('', DayName::of(null, 'en'));
        $this->assertSame('', DayName::of('not a date', 'en'));
    }
}
