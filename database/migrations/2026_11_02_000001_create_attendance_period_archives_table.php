<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_period_archives')) return;

        Schema::create('attendance_period_archives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            // Exact boundaries used when the period ended. They are stored so a later
            // change of the payroll period days can never remap an archived period.
            $table->date('period_start');
            $table->date('period_end');
            // pending: period ended, corrections still allowed until closes_on. closed: rows saved, read-only.
            $table->string('status', 20)->default('pending');
            $table->date('closes_on');
            $table->json('rows')->nullable();
            $table->unsignedInteger('record_count')->default(0);
            $table->unsignedInteger('employee_count')->default(0);
            $table->json('settings')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'period_start', 'period_end'], 'attendance_archive_period_unique');
            $table->index(['company_id', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_period_archives');
    }
};
