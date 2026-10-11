<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_policy_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('setting_key')->unique();
            $table->string('setting_value')->nullable();
            $table->string('label')->nullable();
            $table->timestamps();
        });
        Schema::create('leave_balances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('leave_year');
            $table->decimal('annual_entitlement', 8, 2)->default(0);
            $table->decimal('annual_used', 8, 2)->default(0);
            $table->decimal('casual_used', 8, 2)->default(0);
            $table->decimal('advance_used', 8, 2)->default(0);
            $table->decimal('manual_adjustment', 8, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'leave_year']);
        });
        Schema::table('leave_types', function (Blueprint $table): void {
            $table->string('policy_code', 60)->nullable()->unique();
            $table->decimal('max_days_per_request', 8, 2)->nullable();
            $table->decimal('max_days_per_year', 8, 2)->nullable();
            $table->unsignedSmallInteger('min_service_months')->nullable();
            $table->unsignedSmallInteger('max_occurrences_service')->nullable();
            $table->boolean('requires_document')->default(false);
            $table->string('eligibility_gender', 20)->nullable();
            $table->unsignedInteger('min_employees')->nullable();
        });
        Schema::table('leaves', function (Blueprint $table): void {
            $table->boolean('is_early_grant')->default(false);
            $table->string('early_grant_reason', 500)->nullable();
            $table->foreignId('early_grant_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('early_grant_approved_at')->nullable();
        });
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('leave_entitlement_category', 30)->default('normal')->after('manual_annual_balance');
        });
        foreach ([
            'annual_first_year' => '15', 'annual_second_year' => '21', 'annual_long_service' => '30',
            'annual_long_service_years' => '10', 'annual_age_threshold' => '50', 'annual_disability_days' => '45',
            'annual_hazardous_extra_days' => '7', 'annual_wait_months' => '6', 'annual_prorate_first_year' => '1',
            'allow_early_leave' => '1', 'casual_max_days_year' => '7', 'casual_max_days_request' => '2',
        ] as $key => $value) {
            DB::table('leave_policy_settings')->insert(['setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }
        $rules = [
            'annual' => [['Annual', 'سنوية', 'اعتيادية', 'الإجازة السنوية'], 15, null, 6, null, false, true, null, null],
            'casual' => [['Casual', 'عارضة', 'الإجازة العارضة'], 2, 7, 6, null, false, true, null, null],
            'sick' => [['Sick', 'مرضية', 'الإجازة المرضية'], null, null, null, null, true, false, null, null],
            'unpaid' => [['Unpaid', 'بدون أجر'], null, null, null, null, false, false, null, null],
            'maternity' => [['Maternity', 'وضع', 'إجازة وضع'], 120, null, null, 3, true, false, 'female', null],
            'childcare' => [['Childcare', 'رعاية طفل', 'إجازة رعاية طفل'], 730, null, 12, 3, true, false, 'female', 50],
            'newborn' => [['Newborn', 'ولادة مولود', 'إجازة مولود'], 1, null, null, 3, true, false, null, null],
            'pilgrimage' => [['Pilgrimage', 'حج', 'زيارة بيت المقدس'], 30, null, 60, 1, true, false, null, null],
            'exams' => [['Exams', 'امتحانات', 'إجازة امتحانات'], null, null, null, null, true, false, null, null],
        ];
        foreach ($rules as $code => [$names, $requestCap, $yearCap, $months, $occurrences, $document, $deducts, $gender, $minimumEmployees]) {
            $id = DB::table('leave_types')->whereIn('name', $names)->value('id');
            if ($id) {
                DB::table('leave_types')->where('id', $id)->update([
                    'policy_code' => $code, 'max_days_per_request' => $requestCap, 'max_days_per_year' => $yearCap,
                    'min_service_months' => $months, 'max_occurrences_service' => $occurrences, 'requires_document' => $document,
                    'deducts_annual_balance' => $deducts, 'eligibility_gender' => $gender, 'min_employees' => $minimumEmployees,
                ]);
            }
        }
        foreach ([['إجازة مولود', 'newborn', 1, true], ['إجازة رعاية طفل', 'childcare', 730, false], ['إجازة الحج / القدس', 'pilgrimage', 30, true], ['إجازة امتحانات', 'exams', 1, true]] as [$name, $code, $days, $paid]) {
            if (!DB::table('leave_types')->where('policy_code', $code)->exists()) {
                DB::table('leave_types')->insert(['name' => $name, 'policy_code' => $code, 'default_days' => $days, 'is_paid' => $paid, 'deducts_annual_balance' => false, 'is_active' => true]);
            }
        }
    }
    public function down(): void
    {
        Schema::table('leaves', function (Blueprint $table): void { $table->dropForeign(['early_grant_approved_by']); $table->dropColumn(['early_grant_approved_by', 'early_grant_approved_at', 'early_grant_reason', 'is_early_grant']); });
        Schema::table('leave_types', function (Blueprint $table): void { $table->dropUnique(['policy_code']); $table->dropColumn(['policy_code', 'max_days_per_request', 'max_days_per_year', 'min_service_months', 'max_occurrences_service', 'requires_document', 'eligibility_gender', 'min_employees']); });
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('leave_entitlement_category'));
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('leave_policy_settings');
    }
};
