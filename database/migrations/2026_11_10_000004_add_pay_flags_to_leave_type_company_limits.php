<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_type_company_limits', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_type_company_limits', 'is_paid')) {
                $table->boolean('is_paid')->nullable();
            }
            if (!Schema::hasColumn('leave_type_company_limits', 'deducts_annual_balance')) {
                $table->boolean('deducts_annual_balance')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_type_company_limits', function (Blueprint $table) {
            if (Schema::hasColumn('leave_type_company_limits', 'is_paid')) {
                $table->dropColumn('is_paid');
            }
            if (Schema::hasColumn('leave_type_company_limits', 'deducts_annual_balance')) {
                $table->dropColumn('deducts_annual_balance');
            }
        });
    }
};
