<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fingerprint_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->string('name');
            $table->string('device_type', 60)->default('zkteco');
            $table->string('driver', 60)->default('zkteco_tcp');
            $table->string('ip_address');
            $table->unsignedInteger('port')->default(4370);
            $table->unsignedBigInteger('communication_key')->nullable();
            $table->string('serial_number')->nullable();
            $table->string('firmware_version')->nullable();
            $table->string('timezone')->default('Africa/Cairo');
            $table->string('physical_location')->nullable();
            $table->boolean('sync_enabled')->default(false);
            $table->unsignedInteger('sync_interval_minutes')->default(15);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->string('health_status', 30)->default('unknown');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_connection_failure_at')->nullable();
            $table->dateTime('sync_cursor_timestamp')->nullable();
            $table->string('sync_cursor_hash', 64)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'is_active', 'sync_enabled']);
        });

        Schema::create('fingerprint_device_sync_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('device_id')->constrained('fingerprint_devices')->cascadeOnDelete();
            $table->string('sync_type', 60);
            $table->string('status', 30)->default('pending');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('records_received')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('error_message')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('raw_attendance_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('fingerprint_device_id')->nullable()->constrained('fingerprint_devices')->nullOnDelete();
            $table->string('raw_device_user_id')->nullable();
            $table->string('source', 50)->default('fingerprint');
            $table->string('raw_type', 30);
            $table->dateTime('raw_timestamp');
            $table->json('raw_payload')->nullable();
            $table->string('import_hash', 64)->unique();
            $table->timestamp('imported_at')->nullable();
            $table->string('device_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('processing_status', 30)->default('pending');
            $table->timestamp('processed_at')->nullable();
            $table->boolean('is_duplicate')->default(false);
            $table->foreignId('duplicate_of_id')->nullable()->constrained('raw_attendance_logs')->nullOnDelete();
            $table->timestamps();
            $table->index(['fingerprint_device_id', 'raw_device_user_id', 'raw_timestamp'], 'raw_logs_device_user_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('raw_attendance_logs');
        Schema::dropIfExists('fingerprint_device_sync_logs');
        Schema::dropIfExists('fingerprint_devices');
    }
};
