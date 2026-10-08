<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'net_salary')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('net_salary');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employees') && !Schema::hasColumn('employees', 'net_salary')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->decimal('net_salary', 12, 2)->nullable()->after('gross_salary');
            });
        }
    }
};
