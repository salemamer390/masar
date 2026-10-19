<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('employees')) return;

        $today = Carbon::today();
        DB::table('employees')
            ->whereNull('termination_date')
            ->where(function ($query): void {
                $query->whereNotNull('contract_start_date')->orWhereNotNull('hire_date');
            })
            ->orderBy('id')
            ->each(function (object $employee) use ($today): void {
                $start = $employee->contract_start_date ?: $employee->hire_date;
                if (!$start) return;
                $end = Carbon::parse($start)->startOfDay()->addYear()->subDay();
                while ($end->lt($today)) $end->addYear();
                if (!$employee->contract_end_date || Carbon::parse($employee->contract_end_date)->lt($today)) {
                    DB::table('employees')->where('id', $employee->id)->update([
                        'contract_start_date' => $employee->contract_start_date ?: Carbon::parse($employee->hire_date)->toDateString(),
                        'contract_end_date' => $end->toDateString(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    public function down(): void
    {
        // Corrected contract dates are retained; restoring stale dates would
        // make active employees disappear from payroll again.
    }
};
