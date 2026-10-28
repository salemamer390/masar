<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('employees', 'fingerprint_id')) {
            Schema::table('employees', fn (Blueprint $table) => $table->string('fingerprint_id')->nullable()->after('employee_code'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('employees', 'fingerprint_id')) {
            Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('fingerprint_id'));
        }
    }
};
