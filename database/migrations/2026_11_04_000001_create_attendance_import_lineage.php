<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('attendance_import_batches', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('file_name'); $t->string('layout', 30); $t->longText('original_data'); $t->timestamps();
        });
        Schema::create('attendance_import_days', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('batch_id')->constrained('attendance_import_batches')->restrictOnDelete();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete(); $t->date('work_date');
            $t->foreignId('attendance_id')->nullable()->constrained('attendance')->nullOnDelete();
            $t->longText('original_punches'); $t->longText('current_punches');
            $t->string('issue', 60)->nullable(); $t->string('resolution', 30)->default('pending');
            $t->timestamps(); $t->index(['company_id','resolution','work_date']);
        });
        Schema::create('attendance_punch_revisions', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('import_day_id')->constrained('attendance_import_days')->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('action', 30); $t->text('reason');
            $t->longText('before_data'); $t->longText('after_data'); $t->timestamp('created_at');
        });
    }
    public function down(): void {
        Schema::dropIfExists('attendance_punch_revisions'); Schema::dropIfExists('attendance_import_days'); Schema::dropIfExists('attendance_import_batches');
    }
};
