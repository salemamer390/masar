<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('activity_logs', 'company_id')) {
            Schema::table('activity_logs', function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
                $table->index(['company_id', 'created_at']);
            });
            DB::statement('UPDATE activity_logs a LEFT JOIN users u ON u.id = a.user_id SET a.company_id = u.company_id WHERE a.company_id IS NULL AND u.company_id IS NOT NULL');
        }

        if (!Schema::hasColumn('payroll_setting_changes', 'company_id')) {
            Schema::table('payroll_setting_changes', function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
                $table->index(['company_id', 'created_at']);
            });
        }

        // Earlier migrations added both a named FK and Laravel's generated FK
        // for these same columns. Keep the explicit, descriptive constraint.
        foreach ([
            ['document_expiry_notifications', 'document_expiry_notifications_document_id_foreign'],
            ['employee_emergency_contacts', 'employee_emergency_contacts_employee_id_foreign'],
            ['employee_profile_details', 'employee_profile_details_employee_id_foreign'],
            ['insurance_record_history', 'insurance_record_history_changed_by_foreign'],
            ['insurance_record_history', 'insurance_record_history_employee_id_foreign'],
            ['insurance_record_history', 'insurance_record_history_insurance_record_id_foreign'],
            ['payroll_records', 'payroll_records_payroll_run_id_foreign'],
        ] as [$table, $constraint]) {
            try { DB::statement("ALTER TABLE `{$table}` DROP FOREIGN KEY `{$constraint}`"); } catch (\Throwable) { /* already absent */ }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_setting_changes', 'company_id')) Schema::table('payroll_setting_changes', fn (Blueprint $table) => $table->dropForeign(['company_id'])->dropColumn('company_id'));
        if (Schema::hasColumn('activity_logs', 'company_id')) Schema::table('activity_logs', fn (Blueprint $table) => $table->dropForeign(['company_id'])->dropColumn('company_id'));
    }
};
