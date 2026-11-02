<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Exact period of a run, stored when it is approved. Runs approved before this change stay NULL:
    // their dates are never guessed or backfilled.
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->date('period_start')->nullable()->after('payroll_month');
            $table->date('period_end')->nullable()->after('period_start');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropColumn(['period_start', 'period_end']);
        });
    }
};
