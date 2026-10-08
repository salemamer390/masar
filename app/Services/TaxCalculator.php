<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Tax categories and marginal brackets defined by the approved payroll specification. */
class TaxCalculator
{
    public function calculate(float $annualTaxableBase): array
    {
        // The taxable base is deliberately not rounded or rounded down.
        $base = max(0.0, $annualTaxableBase);
        $categories = $this->categories();
        $index = 0;
        foreach ($categories as $i => $category) {
            if ($base <= $category['up_to']) { $index = $i; break; }
        }
        $category = $categories[$index];
        $annual = $this->annualForCategory($base, $category['brackets']);

        return [
            'base' => $base,
            'category' => $index + 1,
            'category_up_to' => is_infinite($category['up_to']) ? null : $category['up_to'],
            'annual_tax' => $annual,
            'monthly_tax' => $this->round2($annual / 12),
        ];
    }

    public function annualTax(float $annualTaxableBase): float
    {
        return $this->calculate($annualTaxableBase)['annual_tax'];
    }

    /** Excel ROUND(x, 2): halves are rounded away from zero. */
    public function round2(float $value): float
    {
        return round($value + ($value >= 0 ? 0.000000001 : -0.000000001), 2, PHP_ROUND_HALF_UP);
    }

    private function categories(): array
    {
        if (!DB::getSchemaBuilder()->hasTable('tax_categories') || !DB::getSchemaBuilder()->hasTable('tax_brackets')) {
            return $this->defaults();
        }

        $rows = DB::table('tax_categories')->orderBy('sort_order')->get();
        if ($rows->isEmpty()) return $this->defaults();

        return $rows->map(function ($category) {
            $brackets = DB::table('tax_brackets')->where('tax_category_id', $category->id)->orderBy('from_amount')->get()
                ->map(fn ($bracket) => [
                    'from' => (float) $bracket->from_amount,
                    'to' => $bracket->to_amount === null ? INF : (float) $bracket->to_amount,
                    'rate' => (float) $bracket->rate,
                ])->all();
            return ['up_to' => $category->max_income === null ? INF : (float) $category->max_income, 'brackets' => $brackets];
        })->all();
    }

    private function annualForCategory(float $base, array $brackets): float
    {
        $tax = 0.0;
        foreach ($brackets as $bracket) {
            $from = $bracket['from'];
            if ($base <= $from) continue;
            $amount = min($base, $bracket['to']) - $from;
            if ($amount > 0) $tax += $amount * $bracket['rate'];
        }
        return $tax;
    }

    private function defaults(): array
    {
        return [
            ['up_to'=>600000, 'brackets'=>[['from'=>0,'to'=>40000,'rate'=>0],['from'=>40000,'to'=>55000,'rate'=>.10],['from'=>55000,'to'=>70000,'rate'=>.15],['from'=>70000,'to'=>200000,'rate'=>.20],['from'=>200000,'to'=>400000,'rate'=>.225],['from'=>400000,'to'=>INF,'rate'=>.25]]],
            ['up_to'=>700000, 'brackets'=>[['from'=>0,'to'=>55000,'rate'=>.10],['from'=>55000,'to'=>70000,'rate'=>.15],['from'=>70000,'to'=>200000,'rate'=>.20],['from'=>200000,'to'=>400000,'rate'=>.225],['from'=>400000,'to'=>INF,'rate'=>.25]]],
            ['up_to'=>800000, 'brackets'=>[['from'=>0,'to'=>70000,'rate'=>.15],['from'=>70000,'to'=>200000,'rate'=>.20],['from'=>200000,'to'=>400000,'rate'=>.225],['from'=>400000,'to'=>INF,'rate'=>.25]]],
            ['up_to'=>900000, 'brackets'=>[['from'=>0,'to'=>200000,'rate'=>.20],['from'=>200000,'to'=>400000,'rate'=>.225],['from'=>400000,'to'=>INF,'rate'=>.25]]],
            ['up_to'=>1200000, 'brackets'=>[['from'=>0,'to'=>400000,'rate'=>.225],['from'=>400000,'to'=>INF,'rate'=>.25]]],
            ['up_to'=>INF, 'brackets'=>[['from'=>0,'to'=>1200000,'rate'=>.25],['from'=>1200000,'to'=>INF,'rate'=>.275]]],
        ];
    }
}
