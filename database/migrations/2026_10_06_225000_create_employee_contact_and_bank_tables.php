<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('banks')) {
            Schema::create('banks', function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('code', 30)->nullable()->unique();
                $table->boolean('is_active')->default(true)->index();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('employee_emergency_contacts')) {
            Schema::create('employee_emergency_contacts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('phone', 50);
                $table->string('relationship', 100);
                $table->boolean('is_primary')->default(false);
                $table->timestamps();
                $table->index(['employee_id', 'is_primary']);
            });
        }

        if (!Schema::hasTable('employee_bank_accounts')) {
            Schema::create('employee_bank_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
                $table->foreignId('bank_id')->nullable()->constrained()->nullOnDelete();
                $table->string('account_holder_name')->nullable();
                $table->string('account_number', 100)->nullable();
                $table->string('iban', 64)->nullable()->index();
                $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
                $table->boolean('is_primary')->default(false);
                $table->date('effective_from')->nullable()->index();
                $table->date('effective_to')->nullable()->index();
                $table->string('status', 30)->default('Active')->index();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_bank_accounts');
        Schema::dropIfExists('employee_emergency_contacts');
        Schema::dropIfExists('banks');
    }
};
