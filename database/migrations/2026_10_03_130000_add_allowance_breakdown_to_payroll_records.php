<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->decimal('housing_allowance', 12, 2)->default(0)->after('allowances');
            $table->decimal('transportation_allowance', 12, 2)->default(0)->after('housing_allowance');
            $table->decimal('meal_allowance', 12, 2)->default(0)->after('transportation_allowance');
        });
    }
    public function down(): void {
        Schema::table('payroll_records', function (Blueprint $table) {
            $table->dropColumn(['housing_allowance','transportation_allowance','meal_allowance']);
        });
    }
};
