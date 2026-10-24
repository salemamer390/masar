<?php

namespace Tests\Unit;

use App\Models\Salary;
use App\Services\HrOperationalSettingsService;
use App\Services\PayrollCalculator;
use App\Services\PayrollRunService;
use App\Services\PayrollSettingsService;
use App\Services\TaxCalculator;
use PHPUnit\Framework\TestCase;

/** Partial-month rules of the live run: insurance in full, tax and salary prorated, absence/penalty not scaled. */
class PayrollRunProrationTest extends TestCase
{
    private function service(): PayrollRunService
    {
        $settings = new class extends PayrollSettingsService {
            public function number(string $group, string $key, float $default = 0.0): float { return $default; }
            public function rule(string $group, string $key, string $default = ''): string { return $default; }
            public function allowanceDistribution(): array { return []; }
        };
        $calculator = new PayrollCalculator(new TaxCalculator((new TaxCalculator())->defaults()), $settings);

        return new PayrollRunService($calculator, $settings, new HrOperationalSettingsService());
    }

    private function salary(): Salary
    {
        return new Salary([
            'insurance_wage' => 16700, 'applied_insurance_wage' => 16700, 'employee_insurance' => 1837.00,
            'income_tax' => 3686.37, 'martyrs_fund' => 12.77, 'employer_insurance' => 3131.25, 'employee_cost' => 28667.25,
        ]);
    }

    private function net(array $inputs, float $factor): array
    {
        return $this->service()->calculate($this->salary(), ['gross' => 25536.0], $inputs, true, $factor);
    }

    public function test_full_month_is_not_changed(): void
    {
        $r = $this->net([], 1.0);
        $this->assertEqualsWithDelta(19999.86, $r['net_monthly'], 0.01);
        $this->assertEquals(1.0, $r['proration_factor']);
    }

    public function test_partial_month_prorates_salary_and_tax_but_not_insurance(): void
    {
        $r = $this->net([], 22 / 30);
        $this->assertEqualsWithDelta(1837.00, $r['employee_insurance'], 0.001);
        $this->assertEqualsWithDelta(18726.40, $r['entitlements'], 0.01);
        $this->assertEqualsWithDelta(14176.70, $r['net_monthly'], 0.01);
    }

    public function test_absence_and_penalty_are_deducted_in_full_in_a_partial_month(): void
    {
        $r = $this->net(['absence_days' => 2, 'penalty_days' => 5], 22 / 30);
        $this->assertEqualsWithDelta(1702.40, $r['absence_deduction'], 0.01);
        $this->assertEqualsWithDelta(3404.80, $r['penalty_deduction'], 0.01);
        $this->assertEqualsWithDelta(9069.50, $r['net_monthly'], 0.01);
    }

    public function test_variable_earnings_are_not_prorated(): void
    {
        $r = $this->net(['bonus' => 1000], 15 / 30);
        $this->assertEqualsWithDelta(9856.43, $r['net_monthly'], 0.01);
    }
}
