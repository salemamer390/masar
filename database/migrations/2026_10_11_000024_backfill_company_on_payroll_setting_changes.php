<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_setting_changes') || ! Schema::hasColumn('payroll_setting_changes', 'company_id')) {
            return;
        }

        // Preserve the company context for historical changes created by a user.
        // Rows created by a super-admin intentionally remain NULL (global context).
        DB::statement(
            'UPDATE payroll_setting_changes p
             INNER JOIN users u ON u.id = p.changed_by
             SET p.company_id = u.company_id
             WHERE p.company_id IS NULL AND u.company_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        // Backfill is intentionally non-destructive; company context must remain auditable.
    }
};
