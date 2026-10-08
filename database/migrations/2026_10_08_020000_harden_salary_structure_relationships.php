<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('salaries') || !Schema::hasTable('employees') || !Schema::hasColumn('salaries', 'employee_id')) {
            return;
        }

        if (!DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'salaries')
            ->where('CONSTRAINT_NAME', 'fk_salaries_employee')
            ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')
            ->exists()) {
            Schema::table('salaries', function (Blueprint $table) {
                $table->foreign('employee_id', 'fk_salaries_employee')
                    ->references('id')->on('employees')->cascadeOnUpdate()->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('salaries') && DB::getDriverName() === 'mysql') {
            $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'salaries')
                ->where('CONSTRAINT_NAME', 'fk_salaries_employee')
                ->where('CONSTRAINT_TYPE', 'FOREIGN KEY')->exists();
            if ($exists) {
                Schema::table('salaries', fn(Blueprint $table) => $table->dropForeign('fk_salaries_employee'));
            }
        }
    }
};
