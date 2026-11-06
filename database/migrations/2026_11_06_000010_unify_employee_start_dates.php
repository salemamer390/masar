<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employees')) return;

        // Never choose between two historical dates automatically. All preflight
        // checks happen before the first write; no salary/payroll rows are touched.
        $conflicts = DB::table('employees')->whereNotNull('hire_date')
            ->whereNotNull('contract_start_date')->whereColumn('hire_date', '<>', 'contract_start_date')->count();
        if ($conflicts > 0) {
            throw new RuntimeException("Start-date consolidation stopped: {$conflicts} employee(s) have different hire_date and contract_start_date. Run php artisan employees:check-start-dates and reconcile the dates with HR before retrying. No dates were changed.");
        }
        DB::transaction(function (): void {
            DB::table('employees')->whereNull('contract_start_date')->whereNotNull('hire_date')
                ->update(['contract_start_date' => DB::raw('hire_date')]);
            DB::table('employees')->whereNull('hire_date')->whereNotNull('contract_start_date')
                ->update(['hire_date' => DB::raw('contract_start_date')]);
        });
    }

    public function down(): void
    {
        // Both columns are kept for rollback compatibility. Do not discard dates
        // entered since deployment, and never rewrite historic salary/payroll data.
    }
};
