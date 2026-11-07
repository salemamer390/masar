<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PayrollRecord writes and reads is_locked, approved_by and approved_at
 * (record-level lock and per-record approval inside a run) but no migration
 * added the columns, so fresh installs failed when a record was locked or a
 * run was approved. Add them where missing; existing databases that already
 * have the columns are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payroll_records')) {
            return;
        }

        Schema::table('payroll_records', function (Blueprint $table) {
            if (!Schema::hasColumn('payroll_records', 'is_locked')) {
                $table->boolean('is_locked')->default(false)->after('status');
            }
            if (!Schema::hasColumn('payroll_records', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('is_locked');
            }
            if (!Schema::hasColumn('payroll_records', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
        });
    }

    public function down(): void
    {
        // Keep the columns on rollback: production databases may already hold data in them.
    }
};
