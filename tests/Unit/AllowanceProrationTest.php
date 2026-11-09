<?php

namespace Tests\Unit;

use App\Models\PayrollRun;
use App\Models\Salary;
use Carbon\Carbon;
use App\Services\HrOperationalSettingsService;
use App\Services\PayrollCalculator;
use App\Services\PayrollRunService;
use App\Services\PayrollSettingsService;
use App\Services\TaxCalculator;
use PHPUnit\Framework\TestCase;

/** Per-allowance proration: a full-paid allowance is not scaled in a partial month. */
class AllowanceProrationTest extends TestCase
{
    private function service(array $distribution): PayrollRunService
    {
        $settings = new class($distribution) extends PayrollSettingsService {
            public function __construct(private array $distribution) {}

            public function number(string $group, string $key, float $default = 0.0): float { return $default; }

            public function rule(string $group, string $key, string $default = ''): string { return $default; }

            public function allowanceDistribution(): array { return $this->distribution; }
        };
        $calculator = new PayrollCalculator(new TaxCalculator((new TaxCalculator())->defaults()), $settings);

        return new PayrollRunService($calculator, $settings, new HrOperationalSettingsService());
    }

    /** Gross 12000, allowance rate 25%: basic 9600, pool 2400 (840/720/480/360). */
    private function distribution(bool $housingProrated): array
    {
        return [
            'transport' => ['name' => 'بدل انتقال', 'percentage' => 35.0, 'basis' => 'allowance_pool', 'sort_order' => 1, 'prorated' => true],
            'housing' => ['name' => 'بدل سكن', 'percentage' => 30.0, 'basis' => 'allowance_pool', 'sort_order' => 2, 'prorated' => $housingProrated],
            'meal' => ['name' => 'بدل وجبة', 'percentage' => 20.0, 'basis' => 'allowance_pool', 'sort_order' => 3, 'prorated' => true],
            'clothing' => ['name' => 'بدل ملبس', 'percentage' => 15.0, 'basis' => 'allowance_pool', 'sort_order' => 4, 'prorated' => true],
        ];
    }

    private function salary(): Salary
    {
        return new Salary(['no_insurance' => true]);
    }

    private function structure(): array
    {
        return ['gross' => 12000.0, 'basic_salary' => 9600.0, 'allowances' => 2400.0];
    }

    public function test_partial_month_prorates_every_allowance_by_default(): void
    {
        $r = $this->service($this->distribution(true))->calculate($this->salary(), ['gross' => 12000.0], [], true, 0.5);
        $this->assertEqualsWithDelta(4800.0, $r['basic_salary'], 0.001);
        $this->assertEqualsWithDelta(420.0, $r['transportation_allowance'], 0.001);
        $this->assertEqualsWithDelta(360.0, $r['housing_allowance'], 0.001);
        $this->assertEqualsWithDelta(240.0, $r['meal_allowance'], 0.001);
        $this->assertEqualsWithDelta(180.0, $r['clothing_allowance'], 0.001);
        $this->assertEqualsWithDelta(1200.0, $r['allowances'], 0.001);
        $this->assertEqualsWithDelta(6000.0, $r['net_monthly'], 0.01);
    }

    public function test_full_month_is_not_changed_by_the_flag(): void
    {
        $service = $this->service($this->distribution(false));
        $salary = new Salary([
            'no_insurance' => true,
            'basic_salary' => 9600.0, 'transportation_allowance' => 840.0, 'housing_allowance' => 720.0,
            'meal_allowance' => 480.0, 'clothing_allowance' => 360.0, 'net_salary' => 12000.0, 'allowances' => 2400.0,
        ]);
        $calculation = $service->calculate($salary, ['gross' => 12000.0], [], true, 1.0);
        $payload = $service->buildPayload($this->payrollRun(), $salary, $this->structure(), $calculation, [], true, $this->fullMonthWindow());

        $this->assertEqualsWithDelta(2400.0, $payload['allowances'], 0.001);
        $this->assertEqualsWithDelta(12000.0, $payload['net_salary'], 0.01);
        $this->assertEqualsWithDelta(0.0, $payload['calculation_snapshot']['proration_adjustment'], 0.001);
    }

    public function test_empty_distribution_keeps_legacy_lump_proration(): void
    {
        $r = $this->service([])->calculate($this->salary(), ['gross' => 12000.0], [], true, 0.5);
        $this->assertEqualsWithDelta(1200.0, $r['allowances'], 0.001);
        $this->assertEqualsWithDelta(6000.0, $r['net_monthly'], 0.01);
    }

    private function payrollRun(): PayrollRun
    {
        $run = new PayrollRun();
        $run->id = 1;
        $run->settings_snapshot = [];
        return $run;
    }

    private function halfMonthWindow(): array
    {
        return ['start' => Carbon::parse('2026-11-01'), 'end' => Carbon::parse('2026-11-15'), 'days' => 15, 'factor' => 0.5];
    }

    private function fullMonthWindow(): array
    {
        return ['start' => Carbon::parse('2026-11-01'), 'end' => Carbon::parse('2026-11-30'), 'days' => 30, 'factor' => 1.0];
    }

    /** Same gross, same total allowances, different proportions: basic 9000 + 1000/1200/500/300. */
    private function contractualSalary(array $overrides = []): Salary
    {
        return new Salary(array_merge([
            'no_insurance' => true,
            'basic_salary' => 9000.0, 'transportation_allowance' => 1000.0, 'housing_allowance' => 1200.0,
            'meal_allowance' => 500.0, 'clothing_allowance' => 300.0, 'net_salary' => 12000.0, 'allowances' => 3000.0,
        ], $overrides));
    }

    public function test_non_prorated_allowance_is_paid_in_full_in_a_partial_month(): void
    {
        $service = $this->service($this->distribution(false));
        // Engine-matching split: basic 9600 + 840/720/480/360.
        $salary = $this->contractualSalary([
            'basic_salary' => 9600.0, 'transportation_allowance' => 840.0, 'housing_allowance' => 720.0,
            'meal_allowance' => 480.0, 'clothing_allowance' => 360.0, 'allowances' => 2400.0,
        ]);
        $calculation = $service->calculate($salary, ['gross' => 12000.0], [], true, 0.5);
        $payload = $service->buildPayload($this->payrollRun(), $salary, $this->structure(), $calculation, [], true, $this->halfMonthWindow());

        $this->assertEqualsWithDelta(720.0, $payload['housing_allowance'], 0.001);
        $this->assertEqualsWithDelta(1560.0, $payload['allowances'], 0.001);
        $this->assertEqualsWithDelta(6360.0, $payload['monthly_total'], 0.01);
        // The gross extra over the all-prorated baseline: 6360 - 6000 = 360.
        // It is taxed through the marginal path (annualised bracket delta 72)
        // and carries the flat martyrs rate (0.18), so net = 6360 - 72 - 0.18.
        $this->assertEqualsWithDelta(6287.82, $payload['net_salary'], 0.01);
        $this->assertEqualsWithDelta(360.0, $payload['calculation_snapshot']['proration_adjustment'], 0.001);
    }

    public function test_full_paid_allowance_pays_the_contractual_amount_not_the_engine_split(): void
    {
        $service = $this->service($this->distribution(false));
        $salary = $this->contractualSalary();
        $calculation = $service->calculate($salary, ['gross' => 12000.0], [], true, 0.5);
        $payload = $service->buildPayload($this->payrollRun(), $salary, $this->structure(), $calculation, [], true, $this->halfMonthWindow());

        // The displayed pieces: full contractual housing 1200, the rest halved.
        $this->assertEqualsWithDelta(1200.0, $payload['housing_allowance'], 0.001);
        $this->assertEqualsWithDelta(6600.0, $payload['basic_salary'] + $payload['allowances'], 0.001);
        // The net pays exactly the displayed pieces minus the tax the 600
        // extra carries: 6600 - 120 marginal tax - 0.30 martyrs.
        $this->assertEqualsWithDelta(6479.70, $payload['net_salary'], 0.001);
        $this->assertEqualsWithDelta(600.0, $payload['calculation_snapshot']['proration_adjustment'], 0.001);
    }

    public function test_full_paid_extra_is_taxed_through_the_marginal_variable_path(): void
    {
        $service = $this->service($this->distribution(false));
        $salary = $this->contractualSalary(['income_tax' => 300.0, 'martyrs_fund' => 6.0]);
        $calculation = $service->calculate($salary, ['gross' => 12000.0], [], true, 0.5);
        $payload = $service->buildPayload($this->payrollRun(), $salary, $this->structure(), $calculation, [], true, $this->halfMonthWindow());

        // Baseline keeps the day factor: 300 x 0.5 = 150. The 600 extra is
        // taxed with the engine's existing marginal rule (annualised bracket
        // delta on top of the fixed base: 120) and the flat martyrs rate:
        // 6 x 0.5 + 600 x 0.0005 = 3.30.
        $this->assertEqualsWithDelta(270.0, $payload['income_tax'], 0.001);
        $this->assertEqualsWithDelta(3.3, $payload['martyrs_fund'], 0.001);
        $this->assertEqualsWithDelta(6600.0 - 270.0 - 3.3, $payload['net_salary'], 0.001);
        $this->assertEqualsWithDelta(120.0, $payload['calculation_snapshot']['proration_adjustment_tax'], 0.001);
        $this->assertEqualsWithDelta(0.3, $payload['calculation_snapshot']['proration_adjustment_martyrs'], 0.001);
    }

    public function test_lump_only_structure_uses_the_configured_split_for_the_flags(): void
    {
        $service = $this->service($this->distribution(false));
        // Legacy lump-only row: no contractual basic, no itemized allowances.
        $salary = new Salary(['no_insurance' => true, 'allowances' => 2400.0, 'net_salary' => 12000.0]);
        $calculation = $service->calculate($salary, ['gross' => 12000.0], [], true, 0.5);
        $payload = $service->buildPayload($this->payrollRun(), $salary, $this->structure(), $calculation, [], true, $this->halfMonthWindow());

        // Lump 2400 split by the configured 35/30/20/15 with housing full-paid:
        // 2400 x (0.35x0.5 + 0.30 + 0.20x0.5 + 0.15x0.5) = 1560.
        $this->assertEqualsWithDelta(1560.0, $payload['allowances'], 0.001);
        // Itemized columns stay zero so the drawer shows the lump.
        $this->assertEqualsWithDelta(0.0, $payload['housing_allowance'], 0.001);
        // Net = pieces minus the tax the 360 extra carries (72 + 0.18).
        $this->assertEqualsWithDelta(4800.0 + 1560.0 - 72.0 - 0.18, $payload['net_salary'], 0.01);
        $this->assertEqualsWithDelta(360.0, $payload['calculation_snapshot']['proration_adjustment'], 0.001);
    }

    public function test_all_prorated_keeps_the_baseline_net_with_a_differing_split(): void
    {
        $service = $this->service($this->distribution(true));
        $salary = $this->contractualSalary();
        $calculation = $service->calculate($salary, ['gross' => 12000.0], [], true, 0.5);
        $payload = $service->buildPayload($this->payrollRun(), $salary, $this->structure(), $calculation, [], true, $this->halfMonthWindow());

        // Baseline behavior unchanged: no adjustment, engine net stored.
        $this->assertEqualsWithDelta(0.0, $payload['calculation_snapshot']['proration_adjustment'], 0.001);
        $this->assertEqualsWithDelta(6000.0, $payload['net_salary'], 0.001);
        $this->assertEqualsWithDelta(6000.0, $payload['basic_salary'] + $payload['allowances'], 0.001);
    }
}
