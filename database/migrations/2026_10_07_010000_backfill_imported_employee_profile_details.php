<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $profiles = [
            'عامر سالم جمعه' => ['work_pattern' => 'حضوري'],
            'نجية محمود السيد' => ['work_pattern' => 'حضوري'],
            'زياد محمود فرغلي' => ['work_pattern' => 'حضوري'],
            'سيف الدين مجدي' => ['work_pattern' => 'حضوري'],
            'عبدالرحمن طارق احمد' => ['work_pattern' => 'حضوري'],
            'اماني كرم علي قاسم' => ['work_pattern' => 'عن بعد'],
            'محمد صلاح عسكر' => ['work_pattern' => 'حضوري'],
        ];

        DB::transaction(function () use ($profiles): void {
            $employees = DB::table('employees')->whereIn('full_name', array_keys($profiles))->get(['id', 'full_name']);
            foreach ($employees as $employee) {
                $values = array_merge([
                        'nationality' => 'مصري',
                        'weekly_work_days' => 'الأحد إلى الخميس',
                        'daily_work_hours' => 8,
                        'working_hours_text' => '10 AM - 6 PM',
                        'contract_type' => 'محدد المدة (سنة)',
                        'probation_period' => '3 شهور',
                        'annual_leave_days' => 15,
                        'contract_attachment_status' => 'مرفق',
                        'currency' => 'EGP',
                        'updated_at' => now(),
                    ], $profiles[$employee->full_name]);
                $query = DB::table('employee_profile_details')->where('employee_id', $employee->id);
                if ($query->exists()) {
                    $query->update($values);
                } else {
                    DB::table('employee_profile_details')->insert($values + ['employee_id' => $employee->id, 'created_at' => now()]);
                }
            }
        });
    }

    public function down(): void
    {
        // Source-backed profile data is intentionally preserved on rollback.
    }
};
