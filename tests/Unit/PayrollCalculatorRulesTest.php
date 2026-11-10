<?php

namespace Tests\Unit;

use App\Services\PayrollCalculator;
use App\Services\PayrollSettingsService;
use App\Services\TaxCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Covers the live calculation path (PayrollCalculator::monthly) with the documented defaults,
 * without a database. Expected figures were derived independently of the PHP code.
 */
class PayrollCalculatorRulesTest extends TestCase
{
    private function settings(array $numbers = [], array $rules = []): PayrollSettingsService
    {
        return new class($numbers, $rules) extends PayrollSettingsService {
            public function __construct(private array $numbers, private array $rules) {}
            public function number(string $group, string $key, float $default = 0.0): float
            {
                return (float) ($this->numbers["$group.$key"] ?? $default);
            }
            public function rule(string $group, string $key, string $default = ''): string
            {
                return $this->rules["$group.$key"] ?? $default;
            }
            public function allowanceDistribution(): array { return []; }
        };
    }

    private function calculator(array $numbers = [], array $rules = []): PayrollCalculator
    {
        $defaults = (new TaxCalculator())->defaults();

        return new PayrollCalculator(new TaxCalculator($defaults), $this->settings($numbers, $rules));
    }

    public function test_fixed_salary_matches_the_approved_reference(): void
    {
        $r = $this->calculator()->monthly(25536.0);
        $this->assertEqualsWithDelta(3686.37, $r['income_tax'], 0.01);
        $this->assertEqualsWithDelta(19999.86, $r['net_monthly'], 0.01);
    }

    public function test_overtime_bonus_absence_penalty_and_advance_are_all_applied(): void
    {
        $r = $this->calculator()->monthly(25536.0, ['ot_day_hours' => 10, 'ot_night_hours' => 5, 'bonus' => 1000, 'absence_days' => 1, 'penalty_days' => 2, 'loans' => 300]);
        $this->assertEqualsWithDelta(2340.80, $r['overtime_amount'], 0.01);
        // Overtime and bonus are taxable: tax is higher than the structure tax.
        $this->assertEqualsWithDelta(751.68, $r['income_tax_variable'], 0.01);
        $this->assertEqualsWithDelta(4438.05, $r['income_tax'], 0.01);
        $this->assertEqualsWithDelta(851.20, $r['absence_deduction'], 0.01);
        $this->assertEqualsWithDelta(1361.92, $r['penalty_deduction'], 0.01);
        $this->assertEqualsWithDelta(20075.86, $r['net_monthly'], 0.01);
    }

    public function test_absence_base_override_drives_the_deduction_and_is_recorded(): void
    {
        // A base override supplies the contractual base from the salary row
        // (basic + selected allowances); the calculator only applies days/30.
        $r = $this->calculator([], ['absence.calculation_basis' => 'Basic'])
            ->monthly(8600.0, ['absence_days' => 2], null, true, 7000.0);
        $this->assertEqualsWithDelta(466.67, $r['absence_deduction'], 0.01);
        $this->assertEqualsWithDelta(7000.0, $r['absence_base'], 0.01);

        // Without an override the existing bases behave as before.
        $gross = $this->calculator([], ['absence.calculation_basis' => 'Gross'])->monthly(8600.0, ['absence_days' => 1], null, true);
        $this->assertEqualsWithDelta(286.67, $gross['absence_deduction'], 0.01);
        $basic = $this->calculator([], ['absence.calculation_basis' => 'Basic'])->monthly(8600.0, ['absence_days' => 1], null, true);
        $this->assertEqualsWithDelta(229.33, $basic['absence_deduction'], 0.01); // 8600/1.25/30
    }

    public function test_profit_share_is_exempt_but_other_earnings_and_incentives_are_taxed(): void
    {
        $profit = $this->calculator()->monthly(25536.0, ['profit_share' => 5000]);
        $this->assertEqualsWithDelta(3686.37, $profit['income_tax'], 0.01);
        $this->assertEqualsWithDelta(24999.86, $profit['net_monthly'], 0.01);

        $other = $this->calculator()->monthly(25536.0, ['other_earnings' => 2000, 'incentives' => 500]);
        $this->assertEqualsWithDelta(562.50, $other['income_tax_variable'], 0.01);
        $this->assertEqualsWithDelta(21937.36, $other['net_monthly'], 0.01);
    }

    public function test_penalty_days_are_capped_per_month(): void
    {
        $r = $this->calculator()->monthly(25536.0, ['penalty_days' => 8]);
        $this->assertEquals(5.0, $r['penalty_days']);
        $this->assertEquals(8.0, $r['penalty_days_requested']);
        $this->assertEqualsWithDelta(3404.80, $r['penalty_deduction'], 0.01);
        $this->assertEqualsWithDelta(16595.06, $r['net_monthly'], 0.01);

        $uncapped = $this->calculator(['penalty.max_days_per_month' => 0])->monthly(25536.0, ['penalty_days' => 8]);
        $this->assertEquals(8.0, $uncapped['penalty_days']);
    }

    public function test_work_on_official_holiday_and_weekly_rest_is_paid_in_whole_days(): void
    {
        $r = $this->calculator()->monthly(25536.0, ['official_holiday_days' => 1, 'weekly_rest_days' => 1]);
        // Multiplier 2 = two days' wage TOTAL for the worked holiday; the base day is
        // already inside the monthly salary, so only one extra day is added.
        $this->assertEqualsWithDelta(851.20, $r['official_holiday_amount'], 0.01);  // (2 - 1) x daily rate
        $this->assertEqualsWithDelta(851.20, $r['weekly_rest_amount'], 0.01);        // 1 x daily rate
        $this->assertEqualsWithDelta(1702.40, $r['overtime_amount'], 0.01);
        $this->assertEqualsWithDelta(383.04, $r['income_tax_variable'], 0.01);
        $this->assertEqualsWithDelta(21319.22, $r['net_monthly'], 0.01);
    }

    public function test_worked_official_holiday_totals_two_days_wages_not_two_extra_days(): void
    {
        // 200-day employee (6000/30) working one official holiday: total for that day is
        // 400 (200 already inside the salary + 200 added), not 600.
        $r = $this->calculator()->monthly(6000.0, ['official_holiday_days' => 1]);
        $this->assertEqualsWithDelta(200.0, $r['official_holiday_amount'], 0.01);
        $this->assertEqualsWithDelta(200.0, $r['overtime_amount'], 0.01);
        $this->assertEqualsWithDelta(6000.0, $r['gross'], 0.01);
    }

    public function test_official_holiday_multiplier_of_one_adds_nothing_and_below_one_never_deducts(): void
    {
        // A company that configures 1 (= the holiday day counts once, already in salary)
        // gets no extra earning; values below 1 clamp to zero rather than deducting.
        $one = $this->calculator(['overtime.official_holiday_multiplier' => 1])->monthly(6000.0, ['official_holiday_days' => 2]);
        $this->assertEqualsWithDelta(0.0, $one['official_holiday_amount'], 0.01);
        $half = $this->calculator(['overtime.official_holiday_multiplier' => 0.5])->monthly(6000.0, ['official_holiday_days' => 2]);
        $this->assertEqualsWithDelta(0.0, $half['official_holiday_amount'], 0.01);
        $none = $this->calculator()->monthly(6000.0);
        $this->assertEqualsWithDelta(0.0, $none['official_holiday_amount'], 0.01);
    }

    public function test_basis_rules_are_read_from_settings(): void
    {
        $basicOvertime = $this->calculator([], ['overtime.calculation_basis' => 'Basic'])->monthly(25536.0, ['ot_day_hours' => 10]);
        $this->assertEqualsWithDelta(1149.12, $basicOvertime['ot_day_amount'], 0.01);
        $this->assertEqualsWithDelta(20890.43, $basicOvertime['net_monthly'], 0.01);

        $insuranceOnBasic = $this->calculator()->monthly(10000.0);
        $this->assertEquals(8000.0, $insuranceOnBasic['insurance_wage']);
        $this->assertEqualsWithDelta(8478.57, $insuranceOnBasic['net_monthly'], 0.01);

        $insuranceOnGross = $this->calculator([], ['insurance.automatic_calculation_basis' => 'Gross'])->monthly(10000.0);
        $this->assertEquals(10000.0, $insuranceOnGross['insurance_wage']);
        $this->assertEquals(1100.0, $insuranceOnGross['employee_insurance']);
        $this->assertEqualsWithDelta(8302.57, $insuranceOnGross['net_monthly'], 0.01);
    }
}
