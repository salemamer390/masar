<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_requests')) {
            return;
        }

        Schema::create('hr_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('request_type', 40)->index();
            $table->string('title', 200);
            $table->text('details')->nullable();
            $table->longText('payload')->nullable();
            $table->string('status', 30)->default('Pending')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['request_type', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_requests');
    }
};
