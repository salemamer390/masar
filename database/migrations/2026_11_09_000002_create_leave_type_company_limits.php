<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leave types shared by every company (company_id NULL) used to be edited in
 * place, so one company's change of a limit changed it for all companies.
 * A company now keeps its own copy of the four limits for a shared type here.
 * Companies without a row keep using the shared values, so nothing changes
 * for existing data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_type_company_limits')) return;
        Schema::create('leave_type_company_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->decimal('max_days_per_request', 8, 2)->nullable();
            $table->decimal('max_days_per_year', 8, 2)->nullable();
            $table->unsignedSmallInteger('min_service_months')->nullable();
            $table->unsignedSmallInteger('max_occurrences_service')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'leave_type_id'], 'leave_type_company_limits_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_type_company_limits');
    }
};
