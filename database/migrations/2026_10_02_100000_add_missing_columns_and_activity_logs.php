<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Create activity_logs table
        if (!Schema::hasTable('activity_logs')) {
            DB::unprepared("
                CREATE TABLE activity_logs (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    user_id BIGINT UNSIGNED NULL,
                    action VARCHAR(100) NOT NULL,
                    model_type VARCHAR(200) NULL,
                    model_id BIGINT UNSIGNED NULL,
                    description TEXT NULL,
                    ip_address VARCHAR(45) NULL,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_activity_logs_user (user_id),
                    INDEX idx_activity_logs_action (action),
                    CONSTRAINT fk_activity_logs_user
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                ) ENGINE=InnoDB;
            ");
        }

        // 2. Add missing columns to employees
        if (!Schema::hasColumn('employees', 'marital_status')) {
            DB::unprepared("ALTER TABLE employees ADD COLUMN marital_status VARCHAR(50) NULL AFTER address;");
        }
        if (!Schema::hasColumn('employees', 'qualification')) {
            DB::unprepared("ALTER TABLE employees ADD COLUMN qualification VARCHAR(150) NULL AFTER marital_status;");
        }
        if (!Schema::hasColumn('employees', 'contract_duration')) {
            DB::unprepared("ALTER TABLE employees ADD COLUMN contract_duration VARCHAR(100) NULL AFTER contract_end_date;");
        }

        // 3. Add missing columns to salaries
        if (!Schema::hasColumn('salaries', 'status')) {
            DB::unprepared("ALTER TABLE salaries ADD COLUMN status VARCHAR(50) NOT NULL DEFAULT 'Active' AFTER net_salary;");
        }
        if (!Schema::hasColumn('salaries', 'gross_salary')) {
            DB::unprepared("ALTER TABLE salaries ADD COLUMN gross_salary DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER net_salary;");
        }
        if (!Schema::hasColumn('salaries', 'housing_allowance')) {
            DB::unprepared("ALTER TABLE salaries ADD COLUMN housing_allowance DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER allowances;");
        }
        if (!Schema::hasColumn('salaries', 'transportation_allowance')) {
            DB::unprepared("ALTER TABLE salaries ADD COLUMN transportation_allowance DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER housing_allowance;");
        }
        if (!Schema::hasColumn('salaries', 'other_allowances')) {
            DB::unprepared("ALTER TABLE salaries ADD COLUMN other_allowances DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER transportation_allowance;");
        }

        // 4. Add missing columns to documents
        if (!Schema::hasColumn('documents', 'uploaded_by')) {
            DB::unprepared("ALTER TABLE documents ADD COLUMN uploaded_by BIGINT UNSIGNED NULL AFTER uploaded_at;");
        }
        if (!Schema::hasColumn('documents', 'issue_date')) {
            DB::unprepared("ALTER TABLE documents ADD COLUMN issue_date DATE NULL AFTER status;");
        }
        if (!Schema::hasColumn('documents', 'expiry_date')) {
            DB::unprepared("ALTER TABLE documents ADD COLUMN expiry_date DATE NULL AFTER issue_date;");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');

        if (Schema::hasColumn('employees', 'marital_status')) {
            Schema::table('employees', fn($t) => $t->dropColumn('marital_status'));
        }
        if (Schema::hasColumn('employees', 'qualification')) {
            Schema::table('employees', fn($t) => $t->dropColumn('qualification'));
        }
        if (Schema::hasColumn('employees', 'contract_duration')) {
            Schema::table('employees', fn($t) => $t->dropColumn('contract_duration'));
        }
        if (Schema::hasColumn('salaries', 'status')) {
            Schema::table('salaries', fn($t) => $t->dropColumn('status'));
        }
        if (Schema::hasColumn('salaries', 'gross_salary')) {
            Schema::table('salaries', fn($t) => $t->dropColumn('gross_salary'));
        }
        if (Schema::hasColumn('salaries', 'housing_allowance')) {
            Schema::table('salaries', fn($t) => $t->dropColumn('housing_allowance'));
        }
        if (Schema::hasColumn('salaries', 'transportation_allowance')) {
            Schema::table('salaries', fn($t) => $t->dropColumn('transportation_allowance'));
        }
        if (Schema::hasColumn('salaries', 'other_allowances')) {
            Schema::table('salaries', fn($t) => $t->dropColumn('other_allowances'));
        }
        if (Schema::hasColumn('documents', 'uploaded_by')) {
            Schema::table('documents', fn($t) => $t->dropColumn('uploaded_by'));
        }
        if (Schema::hasColumn('documents', 'issue_date')) {
            Schema::table('documents', fn($t) => $t->dropColumn('issue_date'));
        }
        if (Schema::hasColumn('documents', 'expiry_date')) {
            Schema::table('documents', fn($t) => $t->dropColumn('expiry_date'));
        }
    }
};
