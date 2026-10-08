<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\Payroll\TaxEngine;

class TaxSpecificationTest extends TestCase
{
    public function test_category_two_650k_tax(): void
    {
        $engine = new TaxEngine();
        $r = $engine->breakdown(650000);
        $this->assertSame('category_2', $r['category']);
        $this->assertEquals(141250.00, $r['annual_tax']);
        $this->assertEqualsWithDelta(11770.833333, $engine->calculatedMonthlyTax(650000), 0.001);
    }

    public function test_category_six_1_5m_tax(): void
    {
        $engine = new TaxEngine();
        $r = $engine->breakdown(1500000);
        $this->assertSame('category_6', $r['category']);
        $this->assertEquals(382500.00, $r['annual_tax']);
        $this->assertEquals(31875.00, $engine->calculatedMonthlyTax(1500000));
    }
}
