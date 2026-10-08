<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('payroll_records', 'payroll_run_id')) {
            Schema::table('payroll_records', function (Blueprint $table) {
                $table->foreignId('payroll_run_id')->nullable()->after('id')->constrained('payroll_runs')->nullOnDelete();
                $table->index(['payroll_run_id', 'employee_id']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_records', 'payroll_run_id')) {
            Schema::table('payroll_records', function (Blueprint $table) {
                $table->dropForeign(['payroll_run_id']);
                $table->dropIndex(['payroll_run_id', 'employee_id']);
                $table->dropColumn('payroll_run_id');
            });
        }
    }
};
