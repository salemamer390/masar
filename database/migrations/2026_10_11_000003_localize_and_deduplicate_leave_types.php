<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $names = [
            'annual' => 'إجازة سنوية (Annual)',
            'casual' => 'إجازة عارضة (Casual)',
            'sick' => 'إجازة مرضية (Sick)',
            'unpaid' => 'إجازة بدون أجر (Unpaid)',
            'maternity' => 'إجازة وضع (Maternity)',
            'newborn' => 'إجازة مولود (Newborn)',
            'childcare' => 'إجازة رعاية طفل (Childcare)',
            'pilgrimage' => 'إجازة الحج / القدس (Pilgrimage)',
            'exams' => 'إجازة امتحانات (Exams)',
        ];
        foreach ($names as $code => $name) DB::table('leave_types')->where('policy_code', $code)->update(['name' => $name]);
        DB::table('leave_types')->where('name', 'Mission')->update(['name' => 'مهمة عمل (Mission)']);
        DB::table('leave_types')->where('name', 'Bereavement')->update(['name' => 'إجازة وفاة (Bereavement)']);

        $duplicates = [
            'سنوية' => 'annual', 'عارضة' => 'casual', 'مرضية' => 'sick', 'بدون أجر' => 'unpaid',
        ];
        foreach ($duplicates as $duplicateName => $policyCode) {
            $canonicalId = DB::table('leave_types')->where('policy_code', $policyCode)->value('id');
            $duplicateIds = DB::table('leave_types')->where('name', $duplicateName)->pluck('id');
            if ($canonicalId && $duplicateIds->isNotEmpty()) {
                DB::table('leaves')->whereIn('leave_type_id', $duplicateIds)->update(['leave_type_id' => $canonicalId]);
                DB::table('leave_types')->whereIn('id', $duplicateIds)->delete();
            }
        }
    }

    public function down(): void
    {
        // Names are presentation data; intentionally retained on rollback.
    }
};
