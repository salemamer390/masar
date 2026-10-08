<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employee_bank_accounts')) return;
        if (!Schema::hasColumn('employee_bank_accounts', 'effective_from')) Schema::table('employee_bank_accounts', fn (Blueprint $table) => $table->date('effective_from')->nullable()->index()->after('is_primary'));
        if (!Schema::hasColumn('employee_bank_accounts', 'effective_to')) Schema::table('employee_bank_accounts', fn (Blueprint $table) => $table->date('effective_to')->nullable()->index()->after('effective_from'));
        if (!Schema::hasColumn('employee_bank_accounts', 'status')) Schema::table('employee_bank_accounts', fn (Blueprint $table) => $table->string('status', 30)->default('Active')->index()->after('effective_to'));
    }

    public function down(): void
    {
        // Do not destructively remove columns from an existing legacy table.
    }
};
