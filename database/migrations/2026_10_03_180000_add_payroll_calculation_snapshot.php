<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payroll_records') && !Schema::hasColumn('payroll_records', 'calculation_snapshot')) {
            Schema::table('payroll_records', function (Blueprint $table) {
                $table->longText('calculation_snapshot')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_records', 'calculation_snapshot')) {
            Schema::table('payroll_records', fn (Blueprint $table) => $table->dropColumn('calculation_snapshot'));
        }
    }
};
