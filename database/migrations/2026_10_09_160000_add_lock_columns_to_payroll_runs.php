<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('payroll_runs')) {
            return;
        }

        Schema::table('payroll_runs', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_runs', 'locked_by')) {
                $table->unsignedBigInteger('locked_by')->nullable()->after('approved_at');
            }
            if (!Schema::hasColumn('payroll_runs', 'locked_at')) {
                $table->timestamp('locked_at')->nullable()->after('locked_by');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('payroll_runs')) {
            return;
        }

        Schema::table('payroll_runs', function (Blueprint $table) {
            $columns = [];
            if (Schema::hasColumn('payroll_runs', 'locked_by')) $columns[] = 'locked_by';
            if (Schema::hasColumn('payroll_runs', 'locked_at')) $columns[] = 'locked_at';
            if ($columns) $table->dropColumn($columns);
        });
    }
};
