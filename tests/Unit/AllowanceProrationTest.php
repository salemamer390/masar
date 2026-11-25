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

/** Per-allowance proration: window proration is automatic; under the Basic absence basis the flag gates the allowance's absence-day share. */
class AllowanceProrationTest extends TestCase
{
    private function service(array $distribution, array $rules = []): PayrollRunService
    {
        $settings = new class($distribution, $rules) extends PayrollSettingsService {
            public function __construct(private array $distribution, private array $rules) {}

            public function number(string $group, string $key, float $default = 0.0): float { return $default; }

            public function rule(string $group, string $key, string $default = ''): string { return $this->rules["$group.$key"] ?? $default; }

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
        $this->assertEqualsWithDelta(5394.38, $r['net_monthly'], 0.01);
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
        $this->assertEqualsWithDelta(10788.77, $payload['net_salary'], 0.01);
        $this->assertEqualsWithDelta(0.0, $payload['calculation_snapshot']['proration_adjustment'], 0.001);
    }

    public function test_empty_distribution_keeps_legacy_lump_proration(): void
    {
        $r = $this->service([])->calculate($this->salary(), ['gross' => 12000.0], [], true, 0.5);
        $this->assertEqualsWithDelta(1200.0, $r['allowances'], 0.001);
        $this->assertEqualsWithDelta(5394.38, $r['net_monthly'], 0.01);
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

    public function test_allowances_prorate_with_the_window_regardless_of_the_flag(): void
    {
        $service = $this->service($this->distribution(false));
        // Engine-matching split: basic 9600 + 840/720/480/360.
        $salary = $this->contractualSalary([
            'basic_salary' => 9600.0, 'transportation_allowance' => 840.0, 'housing_allowance' => 720.0,
            'meal_allowance' => 480.0, 'clothing_allowance' => 360.0, 'allowances' => 2400.0,
        ]);
        $calculation = $service->calculate($salary, ['gross' => 12000.0], [], true, 0.5);
        $payload = $service->buildPayload($this->payrollRun(), $salary, $this->structure(), $calculation, [], true, $this->halfMonthWindow());

        // Window proration is automatic: every allowance halves with the worked
        // window, flagged or not. The flag now only controls the absence share.
        $this->assertEqualsWithDelta(360.0, $payload['housing_allowance'], 0.001);
        $this->assertEqualsWithDelta(1200.0, $payload['allowances'], 0.001);
        $this->assertEqualsWithDelta(6000.0, $payload['monthly_total'], 0.01);
        $this->assertEqualsWithDelta(5394.38, $payload['net_salary'], 0.01);
        $this->assertEqualsWithDelta(0.0, $payload['calculation_snapshot']['proration_adjustment'], 0.001);
    }

    public function test_contractual_split_prorates_with_the_window(): void
    {
        $service = $this->service($this->distribution(false));
        $salary = $this->contractualSalary();
        $calculation = $service->calculate($salary, ['gross' => 12000.0], [], true, 0.5);
        $payload = $service->buildPayload($this->payrollRun(), $salary, $this->structure(), $calculation, [], true, $this->halfMonthWindow());

        // Every allowance follows the worked window: housing halves like the rest.
        $this->assertEqualsWithDelta(600.0, $payload['housing_allowance'], 0.001);
        $this->assertEqualsWithDelta(6000.0, $payload['basic_salary'] + $payload['allowances'], 0.001);
        $this->assertEqualsWithDelta(5394.38, $payload['net_salary'], 0.001);
        $this->assertEqualsWithDelta(0.0, $payload['calculation_snapshot']['proration_adjustment'], 0.001);
    }

    public function test_partial_month_tax_prorates_by_the_day_factor(): void
    {
        $service = $this->service($this->distribution(false));
        $salary = $this->contractualSalary(['income_tax' => 300.0, 'martyrs_fund' => 6.0]);
        $calculation = $service->calculate($salary, ['gross' => 12000.0], [], true, 0.5);
        $payload = $service->buildPayload($this->payrollRun(), $salary, $this->structure(), $calculation, [], true, $this->halfMonthWindow());

        // No extra is added above the window-prorated pieces, so the structure
        // tax keeps the day factor and martyrs prorates with the window.
        $this->assertEqualsWithDelta(605.62, $payload['income_tax'], 0.001);
        $this->assertEqualsWithDelta(3.0, $payload['martyrs_fund'], 0.001);
        $this->assertEqualsWithDelta(6000.0 - 605.62 - 3.0, $payload['net_salary'], 0.001);
        $this->assertEqualsWithDelta(0.0, $payload['calculation_snapshot']['proration_adjustment_tax'], 0.001);
        $this->assertEqualsWithDelta(0.0, $payload['calculation_snapshot']['proration_adjustment_martyrs'], 0.001);
    }

    public function test_flagged_allowances_lose_their_daily_share_on_absence(): void
    {
        $service = $this->service($this->distribution(false), ['absence.calculation_basis' => 'Basic']);
        // Engine-matching split: basic 9600 + 840/720/480/360; housing unflagged.
        $salary = $this->contractualSalary([
            'basic_salary' => 9600.0, 'transportation_allowance' => 840.0, 'housing_allowance' => 720.0,
            'meal_allowance' => 480.0, 'clothing_allowance' => 360.0, 'allowances' => 2400.0,
        ]);
        $calculation = $service->calculate($salary, ['gross' => 12000.0], ['absence_days' => 2], true, 1.0);
        $payload = $service->buildPayload($this->payrollRun(), $salary, $this->structure(), $calculation, [], true, $this->fullMonthWindow());

        // Absence is priced from the basic only: 2 x 9600/30.
        $this->assertEqualsWithDelta(640.0, $calculation['absence_deduction'], 0.001);
        // Flagged allowances lose their daily share once, on the earnings side:
        // (840 + 480 + 360) x 2/30 = 112; housing is unflagged and pays in full.
        $this->assertEqualsWithDelta(112.0, $calculation['absence_allowance_reduction'], 0.001);
        $this->assertEqualsWithDelta(784.0, $payload['transportation_allowance'], 0.001);
        $this->assertEqualsWithDelta(720.0, $payload['housing_allowance'], 0.001);
        $this->assertEqualsWithDelta(448.0, $payload['meal_allowance'], 0.001);
        $this->assertEqualsWithDelta(336.0, $payload['clothing_allowance'], 0.001);
        $this->assertEqualsWithDelta(2288.0, $payload['allowances'], 0.001);
    }

    public function test_gross_basis_prices_absence_on_the_gross_without_touching_allowances(): void
    {
        // The stub's default absence basis is Gross.
        $service = $this->service($this->distribution(false));
        $salary = $this->contractualSalary([
            'basic_salary' => 9600.0, 'transportation_allowance' => 840.0, 'housing_allowance' => 720.0,
            'meal_allowance' => 480.0, 'clothing_allowance' => 360.0, 'allowances' => 2400.0,
        ]);
        $calculation = $service->calculate($salary, ['gross' => 12000.0], ['absence_days' => 2], true, 1.0);
        $payload = $service->buildPayload($this->payrollRun(), $salary, $this->structure(), $calculation, [], true, $this->fullMonthWindow());

        // Absence on the gross already contains every allowance's share, so the
        // earnings side is left alone: no share is deducted twice.
        $this->assertEqualsWithDelta(800.0, $calculation['absence_deduction'], 0.001); // 2 x 12000/30
        $this->assertEqualsWithDelta(0.0, $calculation['absence_allowance_reduction'], 0.001);
        $this->assertEqualsWithDelta(840.0, $payload['transportation_allowance'], 0.001);
        $this->assertEqualsWithDelta(720.0, $payload['housing_allowance'], 0.001);
        $this->assertEqualsWithDelta(2400.0, $payload['allowances'], 0.001);
    }

    public function test_lump_only_prorates_the_lump_with_the_window(): void
    {
        $service = $this->service($this->distribution(false));
        // Legacy lump-only row: no contractual basic, no itemized allowances.
        $salary = new Salary(['no_insurance' => true, 'allowances' => 2400.0, 'net_salary' => 12000.0]);
        $calculation = $service->calculate($salary, ['gross' => 12000.0], [], true, 0.5);
        $payload = $service->buildPayload($this->payrollRun(), $salary, $this->structure(), $calculation, [], true, $this->halfMonthWindow());

        // The whole lump follows the worked window: 2400 x 0.5 = 1200.
        $this->assertEqualsWithDelta(1200.0, $payload['allowances'], 0.001);
        // Itemized columns stay zero so the drawer shows the lump.
        $this->assertEqualsWithDelta(0.0, $payload['housing_allowance'], 0.001);
        $this->assertEqualsWithDelta(4800.0 + 1200.0 - 605.62, $payload['net_salary'], 0.01);
        $this->assertEqualsWithDelta(0.0, $payload['calculation_snapshot']['proration_adjustment'], 0.001);
    }

    public function test_all_prorated_keeps_the_baseline_net_with_a_differing_split(): void
    {
        $service = $this->service($this->distribution(true));
        $salary = $this->contractualSalary();
        $calculation = $service->calculate($salary, ['gross' => 12000.0], [], true, 0.5);
        $payload = $service->buildPayload($this->payrollRun(), $salary, $this->structure(), $calculation, [], true, $this->halfMonthWindow());

        // Baseline behavior unchanged: no adjustment, engine net stored.
        $this->assertEqualsWithDelta(0.0, $payload['calculation_snapshot']['proration_adjustment'], 0.001);
        $this->assertEqualsWithDelta(5394.38, $payload['net_salary'], 0.001);
        $this->assertEqualsWithDelta(6000.0, $payload['basic_salary'] + $payload['allowances'], 0.001);
    }
}
