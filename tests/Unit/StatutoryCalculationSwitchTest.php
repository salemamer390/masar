<?php

namespace Tests\Unit;

use App\Services\PayrollCalculator;
use App\Services\PayrollSettingsService;
use App\Services\TaxCalculator;
use PHPUnit\Framework\TestCase;

/** Company-wide and per-employee switches for insurance, income tax and the martyrs fund. */
class StatutoryCalculationSwitchTest extends TestCase
{
    private function calculator(array $off = []): PayrollCalculator
    {
        $settings = new class($off) extends PayrollSettingsService {
            public function __construct(private array $off) {}
            public function number(string $group, string $key, float $default = 0.0): float { return $default; }
            public function allowanceDistribution(): array { return []; }
            public function rule(string $group, string $key, string $default = ''): string
            {
                return ($group === 'statutory' && in_array(str_replace('_enabled', '', $key), $this->off, true)) ? 'No' : $default;
            }
        };

        return new PayrollCalculator(new TaxCalculator((new TaxCalculator())->defaults()), $settings);
    }

    public function test_defaults_calculate_everything_as_before(): void
    {
        $r = $this->calculator()->grossResult(20000);
        $this->assertGreaterThan(0, $r['employee_insurance']);
        $this->assertGreaterThan(0, $r['income_tax']);
        $this->assertGreaterThan(0, $r['martyrs_fund']);
        $this->assertGreaterThan(0, $r['employer_insurance']);
    }

    public function test_company_switches_zero_each_item_independently(): void
    {
        $r = $this->calculator(['insurance'])->grossResult(20000);
        $this->assertSame(0.0, (float) $r['employee_insurance']);
        $this->assertSame(0.0, (float) $r['employer_insurance']);
        $this->assertGreaterThan(0, $r['income_tax']);
        $this->assertGreaterThan(0, $r['martyrs_fund']);

        $r = $this->calculator(['tax'])->grossResult(20000);
        $this->assertSame(0.0, (float) $r['income_tax']);
        $this->assertGreaterThan(0, $r['employee_insurance']);
        $this->assertGreaterThan(0, $r['martyrs_fund']);

        $r = $this->calculator(['martyrs'])->grossResult(20000);
        $this->assertSame(0.0, (float) $r['martyrs_fund']);
        $this->assertGreaterThan(0, $r['income_tax']);
    }

    public function test_all_off_makes_net_equal_gross(): void
    {
        $r = $this->calculator(['insurance', 'tax', 'martyrs'])->grossResult(20000);
        $this->assertSame(20000.0, (float) $r['net']);
        $this->assertSame(20000.0, (float) $this->calculator(['insurance', 'tax', 'martyrs'])->calculate('net', 20000)['gross']);
    }

    public function test_employee_flags_only_affect_that_structure(): void
    {
        $calc = $this->calculator();
        $r = $calc->structure(['salary_type' => 'gross', 'salary_input' => 20000, 'no_tax' => true, 'no_martyrs_fund' => true]);
        $this->assertSame(0.0, (float) $r['income_tax']);
        $this->assertSame(0.0, (float) $r['martyrs_fund']);
        $this->assertGreaterThan(0, $r['employee_insurance']);

        $other = $calc->structure(['salary_type' => 'gross', 'salary_input' => 20000]);
        $this->assertGreaterThan(0, $other['income_tax']);
        $this->assertGreaterThan(0, $other['martyrs_fund']);
    }

    public function test_variable_earnings_are_not_taxed_when_tax_is_off(): void
    {
        $m = $this->calculator(['tax'])->monthly(20000, ['bonus' => 3000]);
        $this->assertSame(0.0, (float) $m['income_tax']);
        $on = $this->calculator()->monthly(20000, ['bonus' => 3000]);
        $this->assertGreaterThan(0, $on['income_tax']);
    }

    public function test_net_target_grosses_up_without_deductions(): void
    {
        $r = $this->calculator(['tax', 'martyrs'])->structure(['salary_type' => 'net', 'salary_input' => 15000]);
        $this->assertGreaterThan(15000, $r['gross']);
        $this->assertEqualsWithDelta(15000, $r['net'], 0.05);
        $this->assertSame(0.0, (float) $r['income_tax']);
    }
}
