<?php

return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('insurance_organization_settings')) return;
        \Illuminate\Support\Facades\Schema::table('insurance_organization_settings', function (\Illuminate\Database\Schema\Blueprint $table) {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('insurance_organization_settings', 'applicant_insurance_number')) {
                $table->string('applicant_insurance_number', 100)->nullable()->after('applicant_title');
            }
            if (!\Illuminate\Support\Facades\Schema::hasColumn('insurance_organization_settings', 'applicant_national_id')) {
                $table->string('applicant_national_id', 14)->nullable()->after('applicant_insurance_number');
            }
        });
    }

    public function down(): void
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('insurance_organization_settings')) return;
        \Illuminate\Support\Facades\Schema::table('insurance_organization_settings', function (\Illuminate\Database\Schema\Blueprint $table) {
            foreach (['applicant_insurance_number', 'applicant_national_id'] as $column) {
                if (\Illuminate\Support\Facades\Schema::hasColumn('insurance_organization_settings', $column)) $table->dropColumn($column);
            }
        });
    }
};
