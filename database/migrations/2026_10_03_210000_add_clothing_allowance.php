<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('salaries', 'clothing_allowance')) {
            Schema::table('salaries', function (Blueprint $table) {
                $table->decimal('clothing_allowance', 12, 2)->default(0)->after('meal_allowance');
            });
        }

        if (!Schema::hasColumn('payroll_records', 'clothing_allowance')) {
            Schema::table('payroll_records', function (Blueprint $table) {
                $table->decimal('clothing_allowance', 12, 2)->default(0)->after('meal_allowance');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('salaries', 'clothing_allowance')) {
            Schema::table('salaries', fn (Blueprint $table) => $table->dropColumn('clothing_allowance'));
        }
        if (Schema::hasColumn('payroll_records', 'clothing_allowance')) {
            Schema::table('payroll_records', fn (Blueprint $table) => $table->dropColumn('clothing_allowance'));
        }
    }
};
