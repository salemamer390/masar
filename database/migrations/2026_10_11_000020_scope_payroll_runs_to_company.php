<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payroll_runs') || !Schema::hasColumn('payroll_runs', 'company_id')) return;

        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropUnique('payroll_runs_payroll_month_unique');
            $table->unique(['company_id', 'payroll_month'], 'payroll_runs_company_month_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropUnique('payroll_runs_company_month_unique');
            $table->unique('payroll_month');
        });
    }
};
