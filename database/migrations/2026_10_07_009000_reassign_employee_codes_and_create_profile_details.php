<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $codes = [
            'عامر سالم جمعه' => '101',
            'نجية محمود السيد' => '102',
            'زياد محمود فرغلي' => '103',
            'سيف الدين مجدي' => '104',
            'عبدالرحمن طارق احمد' => '105',
            'اماني كرم علي قاسم' => '106',
            'محمد صلاح عسكر' => '107',
        ];

        DB::transaction(function () use ($codes): void {
            $employees = DB::table('employees')->whereIn('full_name', array_keys($codes))->get(['id', 'full_name']);
            if ($employees->count() !== count($codes)) {
                throw new RuntimeException('Employee-code reassignment stopped: one or more expected employee records were not found.');
            }

            foreach ($employees as $employee) {
                DB::table('employees')->where('id', $employee->id)->update(['employee_code' => '__REASSIGN_'.$employee->id]);
            }
            foreach ($employees as $employee) {
                DB::table('employees')->where('id', $employee->id)->update(['employee_code' => $codes[$employee->full_name]]);
            }
        });

        if (! Schema::hasTable('employee_profile_details')) {
            Schema::create('employee_profile_details', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('nationality', 100)->nullable();
                $table->string('personal_email', 190)->nullable();
                $table->string('work_pattern', 50)->nullable();
                $table->string('weekly_work_days', 100)->nullable();
                $table->decimal('daily_work_hours', 5, 2)->nullable();
                $table->string('working_hours_text', 100)->nullable();
                $table->string('contract_type', 100)->nullable();
                $table->string('probation_period', 100)->nullable();
                $table->decimal('annual_leave_days', 6, 2)->nullable();
                $table->string('contract_attachment_status', 50)->nullable();
                $table->text('additional_conditions')->nullable();
                $table->string('currency', 10)->nullable();
                $table->date('national_id_issue_date')->nullable();
                $table->date('national_id_expiry_date')->nullable();
                $table->string('national_id_place', 150)->nullable();
                $table->string('military_status', 100)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profile_details');
        // Codes are durable business identities and are intentionally not regenerated on rollback.
    }
};
