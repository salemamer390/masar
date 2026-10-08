<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('document_types')) {
            Schema::create('document_types', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->boolean('is_required')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('documents') && !Schema::hasColumn('documents', 'document_type_id')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->foreignId('document_type_id')->nullable()->after('employee_id')->constrained('document_types')->nullOnDelete();
                $table->index(['employee_id', 'document_type_id', 'expiry_date'], 'documents_employee_type_expiry_index');
            });
        }

        if (!Schema::hasTable('document_expiry_notifications')) {
            Schema::create('document_expiry_notifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
                $table->unsignedTinyInteger('threshold_days');
                $table->timestamp('sent_at');
                $table->unique(['document_id', 'threshold_days']);
            });
        }

        if (!Schema::hasTable('hr_holidays')) {
            Schema::create('hr_holidays', function (Blueprint $table) {
                $table->id();
                $table->date('holiday_date')->unique();
                $table->string('name');
                $table->timestamps();
            });
        }

        if (Schema::hasTable('hr_operational_settings')) {
            DB::table('hr_operational_settings')->updateOrInsert(
                ['setting_key' => 'absence_cutoff_time'],
                ['setting_value' => '17:15', 'label' => 'وقت احتساب الغياب اليومي', 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_expiry_notifications');
        Schema::dropIfExists('hr_holidays');
        if (Schema::hasTable('documents') && Schema::hasColumn('documents', 'document_type_id')) {
            Schema::table('documents', function (Blueprint $table) {
                $table->dropIndex('documents_employee_type_expiry_index');
                $table->dropConstrainedForeignId('document_type_id');
            });
        }
        Schema::dropIfExists('document_types');
    }
};
