<?php

namespace Tests\Unit;

use App\Services\Payroll\GrossUpEngine;
use App\Services\Payroll\OvertimeEngine;
use App\Services\PayrollSettingsService;
use PHPUnit\Framework\TestCase;

class PayrollSpecificationTest extends TestCase
{
    public function test_overtime_uses_gross(): void
    {
        // Keep this a real unit test: the expected calculation uses the
        // documented defaults and must not require a database connection.
        $settings = new class extends PayrollSettingsService {
            public function hoursDivisor(): float { return 240; }
            public function daysDivisor(): float { return 30; }
            public function dayOvertimeMultiplier(): float { return 1.35; }
            public function nightOvertimeMultiplier(): float { return 1.70; }
            public function officialHolidayMultiplier(): float { return 2; }
            public function weeklyRestMultiplier(): float { return 1; }
        };
        $engine = new OvertimeEngine($settings);
        $r = $engine->calculate(24000, 10, 5, 2, 1);
        $this->assertEquals(1350.00, $r['day_overtime']);
        $this->assertEquals(850.00, $r['night_overtime']);
        $this->assertEquals(1600.00, $r['official_holiday']); // 2 days x daily x (2 - 1): the base day is already inside the salary
        $this->assertEquals(800.00, $r['weekly_rest']);
        $this->assertEquals(4600.00, $r['total_overtime']);
    }

    public function test_gross_up_solver_converges(): void
    {
        $solver = new GrossUpEngine();
        $gross = $solver->solve(22000, function(float $gross): float {
            $insurance = min(16700, max(2700, $gross / 1.25));
            $employeeInsurance = $insurance * .11;
            $taxable = max(0, $gross * 12 - $employeeInsurance * 12 - 20000);
            $tax = $this->tax($taxable) / 12;
            $tax = round($tax * 2) / 2;
            $martyrs = round($gross * .0005, 2);
            return $gross - $employeeInsurance - $tax - $martyrs;
        });
        $this->assertEqualsWithDelta(28118.56, $gross, .01);
    }

    private function tax(float $x): float
    {
        $brackets = $x <= 600000 ? [[0,40000,0],[40000,55000,.1],[55000,70000,.15],[70000,200000,.2],[200000,400000,.225],[400000,INF,.25]]
            : ($x <= 700000 ? [[0,55000,.1],[55000,70000,.15],[70000,200000,.2],[200000,400000,.225],[400000,INF,.25]]
            : ($x <= 800000 ? [[0,70000,.15],[70000,200000,.2],[200000,400000,.225],[400000,INF,.25]]
            : ($x <= 900000 ? [[0,200000,.2],[200000,400000,.225],[400000,INF,.25]]
            : ($x <= 1200000 ? [[0,400000,.225],[400000,INF,.25]] : [[0,1200000,.25],[1200000,INF,.275]]))));
        $tax=0;
        foreach($brackets as [$from,$to,$rate]){ if($x<=$from) continue; $amount=$to===INF?$x-$from:min($x,$to)-$from; if($amount>0)$tax+=$amount*$rate; if($to!==INF&&$x<=$to)break; }
        return $tax;
    }
}
