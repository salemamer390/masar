<?php

namespace App\Services\Payroll;

use Illuminate\Support\Facades\DB;

class TaxEngine
{
    public function personalExemption(): float
    {
        $value = DB::getSchemaBuilder()->hasTable('tax_settings')
            ? DB::table('tax_settings')->where('setting_key', 'personal_exemption')->value('setting_value')
            : null;
        return $value !== null ? (float) $value : (float) config('payroll.tax.personal_exemption', 20000);
    }

    public function settings(): array
    {
        $rows = DB::getSchemaBuilder()->hasTable('tax_categories')
            ? DB::table('tax_categories')->orderBy('sort_order')->get()
            : collect();
        if ($rows->isEmpty()) return config('payroll.tax.categories', []);
        return $rows->mapWithKeys(fn($r) => [$r->category_key => ['label' => $r->label, 'max' => $r->max_income === null ? null : (float)$r->max_income]])->all();
    }

    public function categoryFor(float $taxableIncome): string
    {
        $income = max(0, $taxableIncome);
        foreach ($this->settings() as $key => $category) {
            if ($category['max'] === null || $income <= $category['max']) return $key;
        }
        return 'category_6';
    }

    public function categoryLabel(string $category): string
    {
        return $this->settings()[$category]['label'] ?? $category;
    }

    public function bracketsFor(string $category): array
    {
        if (DB::getSchemaBuilder()->hasTable('tax_brackets')) {
            $rows = DB::table('tax_brackets as b')
                ->join('tax_categories as c', 'c.id', '=', 'b.tax_category_id')
                ->where('c.category_key', $category)
                ->orderBy('b.from_amount')
                ->get(['b.from_amount as from', 'b.to_amount as to', 'b.rate']);
            if ($rows->isNotEmpty()) {
                return $rows->map(fn($r) => ['from'=>(float)$r->from, 'to'=>$r->to === null ? INF : (float)$r->to, 'rate'=>(float)$r->rate])->all();
            }
        }
        $fallback = config('payroll.tax.brackets', []);
        return $fallback[$category] ?? [];
    }

    public function breakdown(float $taxableIncome): array
    {
        $taxableIncome = max(0, $taxableIncome);
        $category = $this->categoryFor($taxableIncome);
        $rows = [];
        $annualTax = 0.0;
        foreach ($this->bracketsFor($category) as $bracket) {
            $lower = (float)$bracket['from'];
            $upper = $bracket['to'];
            if ($taxableIncome <= $lower) continue;
            $taxableAmount = $upper === INF ? $taxableIncome - $lower : min($taxableIncome, $upper) - $lower;
            if ($taxableAmount <= 0) continue;
            $tax = $taxableAmount * (float)$bracket['rate'];
            $annualTax += $tax;
            $rows[] = ['from'=>$lower,'to'=>$upper,'rate'=>(float)$bracket['rate'],'taxable_amount'=>$taxableAmount,'tax'=>$tax];
            if ($upper !== INF && $taxableIncome <= $upper) break;
        }
        return ['category'=>$category,'category_label'=>$this->categoryLabel($category),'taxable_income'=>round($taxableIncome,2),'annual_tax'=>round($annualTax,2),'rows'=>$rows];
    }

    public function annualTax(float $taxableIncome): float { return (float)$this->breakdown($taxableIncome)['annual_tax']; }

    public function monthlyTax(float $taxableIncome): float
    {
        $monthly = $this->annualTax($taxableIncome) / 12;
        return match (config('payroll.tax.monthly_rounding', 'half')) {
            'integer' => round($monthly),
            'decimal_2' => round($monthly, 2),
            'half' => round($monthly * 2) / 2,
            default => $monthly,
        };
    }

    public function calculatedMonthlyTax(float $taxableIncome): float { return $this->annualTax($taxableIncome) / 12; }
}
