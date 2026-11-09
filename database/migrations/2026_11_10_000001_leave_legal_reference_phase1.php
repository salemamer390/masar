<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Leave settings phase 1 (Law 14/2025):
 * - leaves keep the birth date (post-birth minimum, art. 54), the attached proof;
 * - a company can switch the document requirement per leave type;
 * - new company policy keys and the contagious-contact leave type (art. 132).
 * Nothing existing is overwritten: existing company values stay as they are.
 */
return new class extends Migration
{
    private const POLICIES = ['maternity_months' => '4', 'childcare_min_gap_years' => '2', 'exam_notice_days' => '10', 'contagious_max_months' => '3'];

    public function up(): void
    {
        Schema::table('leaves', function (Blueprint $table): void {
            if (!Schema::hasColumn('leaves', 'birth_date')) $table->date('birth_date')->nullable();
            if (!Schema::hasColumn('leaves', 'document_path')) $table->string('document_path', 500)->nullable();
            if (!Schema::hasColumn('leaves', 'document_name')) $table->string('document_name', 255)->nullable();
        });
        if (Schema::hasTable('leave_type_company_limits') && !Schema::hasColumn('leave_type_company_limits', 'requires_document')) {
            Schema::table('leave_type_company_limits', function (Blueprint $table): void { $table->boolean('requires_document')->nullable(); });
        }
        if (Schema::hasTable('leave_policy_settings')) {
            $companyIds = Schema::hasColumn('leave_policy_settings', 'company_id')
                ? array_merge([null], DB::table('companies')->pluck('id')->all())
                : [null];
            foreach ($companyIds as $companyId) {
                foreach (self::POLICIES as $key => $value) {
                    $exists = DB::table('leave_policy_settings')->where('setting_key', $key)
                        ->when($companyId === null, fn ($q) => $q->whereNull('company_id'), fn ($q) => $q->where('company_id', $companyId))->exists();
                    if ($exists) continue;
                    DB::table('leave_policy_settings')->insert(['company_id' => $companyId, 'setting_key' => $key, 'setting_value' => $value, 'label' => $key, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        if (!DB::table('leave_types')->where('policy_code', 'contagious_contact')->exists()) {
            DB::table('leave_types')->insert([
                'name' => 'إجازة مخالطة مريض بمرض معد', 'policy_code' => 'contagious_contact', 'default_days' => null,
                'is_paid' => true, 'deducts_annual_balance' => false, 'is_active' => false, 'requires_document' => true,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('leave_types')->where('policy_code', 'contagious_contact')->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('leaves')->whereColumn('leaves.leave_type_id', 'leave_types.id'))->delete();
        DB::table('leave_policy_settings')->whereIn('setting_key', array_keys(self::POLICIES))->delete();
        if (Schema::hasColumn('leave_type_company_limits', 'requires_document')) Schema::table('leave_type_company_limits', fn (Blueprint $t) => $t->dropColumn('requires_document'));
        Schema::table('leaves', fn (Blueprint $t) => $t->dropColumn(['birth_date', 'document_path', 'document_name']));
    }
};
