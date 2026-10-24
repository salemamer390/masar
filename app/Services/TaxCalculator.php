<?php

namespace App\Services;

use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Income-tax categories and marginal brackets. Each company owns its own set
 * (editable from Settings → شرائح الضرائب); the built-in law table is used when
 * a company has no rows.
 */
class TaxCalculator
{
    /** @var array<string, array> categories per company, loaded lazily */
    private array $categoriesCache = [];

    /** Optional fixed categories (used by unit tests, bypasses the database). */
    private ?array $fixedCategories;

    public function __construct(?array $fixedCategories = null)
    {
        $this->fixedCategories = $fixedCategories;
    }

    public function calculate(float $annualTaxableBase): array
    {
        // The taxable base is deliberately not rounded.
        $base = max(0.0, $annualTaxableBase);
        $categories = $this->categories();
        $index = count($categories) - 1;
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
        if ($this->fixedCategories !== null) {
            return $this->fixedCategories;
        }

        $companyId = CompanyContext::id();
        $cacheKey = $companyId === null ? 'global' : (string) $companyId;
        if (isset($this->categoriesCache[$cacheKey])) {
            return $this->categoriesCache[$cacheKey];
        }

        $schema = DB::getSchemaBuilder();
        if (!$schema->hasTable('tax_categories') || !$schema->hasTable('tax_brackets')) {
            return $this->categoriesCache[$cacheKey] = $this->defaults();
        }

        $query = DB::table('tax_categories')->orderBy('sort_order');
        if (Schema::hasColumn('tax_categories', 'company_id')) {
            $query->where('company_id', $companyId);
        }
        $rows = $query->get();
        if ($rows->isEmpty() && $companyId !== null && Schema::hasColumn('tax_categories', 'company_id')) {
            // Company without its own table yet: fall back to the shared template.
            $rows = DB::table('tax_categories')->whereNull('company_id')->orderBy('sort_order')->get();
        }
        if ($rows->isEmpty()) {
            return $this->categoriesCache[$cacheKey] = $this->defaults();
        }

        $bracketsByCategory = DB::table('tax_brackets')
            ->whereIn('tax_category_id', $rows->pluck('id')->all())
            ->orderBy('from_amount')
            ->get()
            ->groupBy('tax_category_id');

        return $this->categoriesCache[$cacheKey] = $rows->map(function ($category) use ($bracketsByCategory) {
            $brackets = ($bracketsByCategory[$category->id] ?? collect())
                ->map(fn ($bracket) => [
                    'from' => (float) $bracket->from_amount,
                    'to' => $bracket->to_amount === null ? INF : (float) $bracket->to_amount,
                    'rate' => (float) $bracket->rate,
                ])->values()->all();

            return ['up_to' => $category->max_income === null ? INF : (float) $category->max_income, 'brackets' => $brackets];
        })->values()->all();
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

    public function defaults(): array
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
