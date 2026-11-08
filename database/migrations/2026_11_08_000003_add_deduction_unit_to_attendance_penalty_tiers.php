<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each tier's deduction amount is denominated in days (existing rows) or minutes:
        // a company may penalize a violation by e.g. 30 minutes or 1 day.
        if (Schema::hasTable('attendance_penalty_tiers') && !Schema::hasColumn('attendance_penalty_tiers', 'unit')) {
            Schema::table('attendance_penalty_tiers', function (Blueprint $table) {
                $table->string('unit', 10)->default('days')->after('deduction_days');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('attendance_penalty_tiers') && Schema::hasColumn('attendance_penalty_tiers', 'unit')) {
            Schema::table('attendance_penalty_tiers', fn (Blueprint $table) => $table->dropColumn('unit'));
        }
    }
};
