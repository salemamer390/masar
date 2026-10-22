<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_records') || Schema::hasColumn('payroll_records', 'monthly_total')) {
            return;
        }

        Schema::table('payroll_records', function (Blueprint $table): void {
            $table->decimal('monthly_total', 15, 2)->default(0)->after('gross_salary');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('payroll_records') && Schema::hasColumn('payroll_records', 'monthly_total')) {
            Schema::table('payroll_records', fn (Blueprint $table) => $table->dropColumn('monthly_total'));
        }
    }
};
