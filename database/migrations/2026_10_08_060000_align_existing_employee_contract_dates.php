<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employees')) {
            return;
        }

        DB::table('employees')
            ->whereNotNull('hire_date')
            ->orderBy('id')
            ->each(function (object $employee): void {
                $hireDate = Carbon::parse($employee->hire_date)->toDateString();
                DB::table('employees')->where('id', $employee->id)->update([
                    'contract_start_date' => $hireDate,
                    'contract_end_date' => $employee->contract_end_date
                        ? Carbon::parse($employee->contract_end_date)->toDateString()
                        : Carbon::parse($hireDate)->addYear()->toDateString(),
                ]);
            });
    }

    public function down(): void
    {
        // Existing employee dates are corrected data and are kept on rollback.
    }
};
