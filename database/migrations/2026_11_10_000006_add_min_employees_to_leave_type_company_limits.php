<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company-level override for a leave type's minimum-establishment-size rule
 * (e.g. childcare's statutory 50). Stored per company so one company's choice
 * never edits the shared type row other companies use.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('leave_type_company_limits') || Schema::hasColumn('leave_type_company_limits', 'min_employees')) return;
        Schema::table('leave_type_company_limits', function (Blueprint $table): void {
            $table->unsignedSmallInteger('min_employees')->nullable()->after('max_occurrences_service');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('leave_type_company_limits') || !Schema::hasColumn('leave_type_company_limits', 'min_employees')) return;
        Schema::table('leave_type_company_limits', function (Blueprint $table): void {
            $table->dropColumn('min_employees');
        });
    }
};
