<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employees') || !Schema::hasColumn('employees', 'company_id')) return;
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('employee_code');
            $table->unique(['company_id', 'employee_code'], 'employees_company_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('employees_company_code_unique');
            $table->unique('employee_code');
        });
    }
};
