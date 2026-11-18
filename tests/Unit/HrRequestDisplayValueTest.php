<?php

namespace Tests\Unit;

use App\Models\HrRequest;
use Tests\TestCase;

class HrRequestDisplayValueTest extends TestCase
{
    public function test_bonus_family_amounts_are_displayed(): void
    {
        foreach (['bonus', 'incentive', 'commission', 'profit_share'] as $type) {
            $request = new HrRequest(['request_type' => $type, 'payload' => ['amount' => 500, 'request_date' => '2026-11-16']]);

            $this->assertSame('500.00 ج.م', $request->display_value, "Display value for {$type}");
        }
    }

    public function test_bonus_family_without_amount_still_shows_dash(): void
    {
        $request = new HrRequest(['request_type' => 'bonus', 'payload' => ['request_date' => '2026-11-16']]);

        $this->assertSame('—', $request->display_value);
    }

    public function test_other_types_display_rules_are_unchanged(): void
    {
        $this->assertSame('1,000.00 ج.م', (new HrRequest(['request_type' => 'advance', 'payload' => ['amount' => 1000]]))->display_value);
        $this->assertSame('120 دقيقة', (new HrRequest(['request_type' => 'overtime', 'payload' => ['hours' => 2]]))->display_value);
        $this->assertSame('750.00 ج.م', (new HrRequest(['request_type' => 'other', 'payload' => ['amount' => 750]]))->display_value);
        $this->assertSame('—', (new HrRequest(['request_type' => 'permission', 'payload' => []]))->display_value);
    }
}
