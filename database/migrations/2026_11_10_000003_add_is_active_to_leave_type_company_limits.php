<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_type_company_limits', function (Blueprint $table) {
            if (!Schema::hasColumn('leave_type_company_limits', 'is_active')) {
                $table->boolean('is_active')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_type_company_limits', function (Blueprint $table) {
            if (Schema::hasColumn('leave_type_company_limits', 'is_active')) {
                $table->dropColumn('is_active');
            }
        });
    }
};
