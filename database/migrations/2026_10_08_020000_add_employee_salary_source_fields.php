<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('employees')) return;

        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'gross_salary')) {
                $table->decimal('gross_salary', 12, 2)->nullable()->after('email');
            }
            if (!Schema::hasColumn('employees', 'net_salary')) {
                $table->decimal('net_salary', 12, 2)->nullable()->after('gross_salary');
            }
        });

        if (Schema::hasTable('salaries')) {
            foreach (DB::table('employees')->select('id')->get() as $employee) {
                $salary = DB::table('salaries')
                    ->where('employee_id', $employee->id)
                    ->orderByDesc('effective_from')
                    ->orderByDesc('id')
                    ->first();
                if ($salary) {
                    DB::table('employees')->where('id', $employee->id)->update([
                        'gross_salary' => $salary->gross_salary,
                        'net_salary' => $salary->net_salary,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('employees')) return;

        $columns = [];
        if (Schema::hasColumn('employees', 'net_salary')) $columns[] = 'net_salary';
        if (Schema::hasColumn('employees', 'gross_salary')) $columns[] = 'gross_salary';
        if ($columns) Schema::table('employees', fn (Blueprint $table) => $table->dropColumn($columns));
    }
};
