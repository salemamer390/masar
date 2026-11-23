<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const KEYS = [
        ['insurance', 'employee_insurance_rate', 11],
        ['insurance', 'employer_insurance_rate', 18.75],
        ['insurance', 'martyrs_fund_rate', 0.05],
        ['insurance', 'insurance_minimum', 2700],
        ['insurance', 'insurance_maximum', 16700],
        ['tax', 'personal_exemption', 1667],
        ['tax', 'tax_deductible_contributions', 0],
        ['tax', 'profit_share_exempt_percent', 100],
    ];

    /**
     * Social insurance numbers and the income tax table become platform-wide
     * (company_id NULL rows), edited only from the owner panel. So that nothing
     * changes for existing payrolls, each global value is seeded from the
     * companies' current values: the value most companies use, ties going to
     * the lowest company id. The per-company rows are left in place and are no
     * longer read. A global setting row that already exists is kept as is.
     */
    public function up(): void
    {
        if (! Schema::hasTable('payroll_calculation_settings') || ! Schema::hasColumn('payroll_calculation_settings', 'company_id')) {
            return;
        }

        DB::transaction(function (): void {
            $now = now();
            foreach (self::KEYS as [$group, $key, $default]) {
                if (DB::table('payroll_calculation_settings')->whereNull('company_id')->where('setting_group', $group)->where('setting_key', $key)->exists()) {
                    continue;
                }
                $rows = DB::table('payroll_calculation_settings')->whereNotNull('company_id')
                    ->where('setting_group', $group)->where('setting_key', $key)->where('is_active', 1)
                    ->orderBy('company_id')->get(['company_id', 'setting_value', 'value_type', 'unit', 'label', 'description']);
                $value = $default;
                $template = $rows->first();
                if ($rows->isNotEmpty()) {
                    $counts = [];
                    foreach ($rows as $row) {
                        $counts[rtrim(rtrim(number_format((float) $row->setting_value, 6, '.', ''), '0'), '.')] = ($counts[rtrim(rtrim(number_format((float) $row->setting_value, 6, '.', ''), '0'), '.')] ?? 0) + 1;
                    }
                    $best = max($counts);
                    foreach ($rows as $row) {
                        $norm = rtrim(rtrim(number_format((float) $row->setting_value, 6, '.', ''), '0'), '.');
                        if ($counts[$norm] === $best) {
                            $value = $row->setting_value;
                            $template = $row;
                            break;
                        }
                    }
                }
                DB::table('payroll_calculation_settings')->insert([
                    'company_id' => null, 'setting_group' => $group, 'setting_key' => $key, 'setting_value' => $value,
                    'value_type' => $template->value_type ?? 'number', 'unit' => $template->unit ?? null,
                    'label' => $template->label ?? $key, 'description' => $template->description ?? null,
                    'is_editable' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            if (! Schema::hasTable('tax_categories') || ! Schema::hasTable('tax_brackets') || ! Schema::hasColumn('tax_categories', 'company_id')) {
                return;
            }
            // One signature per company: its categories and brackets, in order.
            $signatures = [];
            foreach (DB::table('tax_categories')->whereNotNull('company_id')->orderBy('company_id')->orderBy('sort_order')->get() as $category) {
                $brackets = DB::table('tax_brackets')->where('tax_category_id', $category->id)->orderBy('from_amount')->get()
                    ->map(fn ($b) => [(float) $b->from_amount, $b->to_amount === null ? null : (float) $b->to_amount, (float) $b->rate, (int) $b->sort_order])->all();
                $signatures[$category->company_id][] = ['category' => $category, 'brackets' => $brackets,
                    'sig' => [$category->label, (float) $category->min_income, $category->max_income === null ? null : (float) $category->max_income, $brackets]];
            }
            if ($signatures === []) {
                return; // No company table: the built-in law table is used until the owner saves one.
            }
            $hashes = [];
            foreach ($signatures as $companyId => $set) {
                $hashes[$companyId] = md5(json_encode(array_column($set, 'sig')));
            }
            $counts = array_count_values($hashes);
            $best = max($counts);
            $chosen = null;
            foreach ($hashes as $companyId => $hash) {
                if ($counts[$hash] === $best) {
                    $chosen = $companyId;
                    break;
                }
            }
            $old = DB::table('tax_categories')->whereNull('company_id')->pluck('id')->all();
            if ($old) {
                DB::table('tax_brackets')->whereIn('tax_category_id', $old)->delete();
                DB::table('tax_categories')->whereIn('id', $old)->delete();
            }
            foreach ($signatures[$chosen] as $entry) {
                $c = $entry['category'];
                $newId = DB::table('tax_categories')->insertGetId([
                    'company_id' => null, 'category_key' => $c->category_key, 'label' => $c->label,
                    'min_income' => $c->min_income, 'max_income' => $c->max_income, 'sort_order' => $c->sort_order,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($entry['brackets'] as [$from, $to, $rate, $sort]) {
                    DB::table('tax_brackets')->insert([
                        'tax_category_id' => $newId, 'from_amount' => $from, 'to_amount' => $to, 'rate' => $rate,
                        'sort_order' => $sort, 'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: the per-company rows were never changed, and the
        // global rows are harmless to keep.
    }
};
