<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Imported fixed-term contracts start in 2026; their supplied end dates used the same month/day in 2026.
        // Make them end one year later without changing employee identity, salaries, or payroll history.
        DB::table('employees')->whereNotNull('contract_start_date')->whereYear('contract_start_date', 2026)
            ->orderBy('id')->each(function (object $employee): void {
                $start = Carbon::parse($employee->contract_start_date);
                DB::table('employees')->where('id', $employee->id)->update([
                    'contract_end_date' => $start->copy()->addYear()->subDay()->toDateString(),
                    'updated_at' => now(),
                ]);
            });

        if (!Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->string('type');
                $table->morphs('notifiable');
                $table->text('data');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
                $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
