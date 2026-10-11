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
            $table->dropUnique('national_id');
            $table->unique(['company_id', 'national_id'], 'employees_company_national_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique('employees_company_national_id_unique');
            $table->unique('national_id');
        });
    }
};
