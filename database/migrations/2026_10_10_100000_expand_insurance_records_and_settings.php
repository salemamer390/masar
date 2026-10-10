<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('insurance_records')) {
            Schema::table('insurance_records', function (Blueprint $table) {
                if (!Schema::hasColumn('insurance_records', 'insurance_office')) $table->string('insurance_office', 150)->nullable()->after('job_code');
                if (!Schema::hasColumn('insurance_records', 'insurance_category')) $table->string('insurance_category', 100)->default('العاملون لدى الغير')->after('insurance_office');
                if (!Schema::hasColumn('insurance_records', 'registration_deadline')) $table->date('registration_deadline')->nullable()->after('insurance_start_date');
            });
        }
        if (!Schema::hasTable('insurance_organization_settings')) {
            Schema::create('insurance_organization_settings', function (Blueprint $table) {
                $table->id();
                $table->string('office_name', 150)->nullable();
                $table->string('establishment_number', 100)->nullable();
                $table->string('organization_name', 190)->nullable();
                $table->string('address', 255)->nullable();
                $table->string('applicant_name', 150)->nullable();
                $table->string('applicant_title', 150)->nullable();
                $table->string('phone', 50)->nullable();
                $table->unsignedSmallInteger('grace_days')->default(30);
                $table->timestamps();
            });
            DB::table('insurance_organization_settings')->insert(['grace_days' => 30, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_organization_settings');
        if (Schema::hasTable('insurance_records')) Schema::table('insurance_records', function (Blueprint $table) {
            foreach (['insurance_office','insurance_category','registration_deadline'] as $column) if (Schema::hasColumn('insurance_records', $column)) $table->dropColumn($column);
        });
    }
};
