<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_opening_entries')) return;
        Schema::create('leave_opening_entries', function (Blueprint $table): void {
            // Explicit, dated, audited opening carryover entries. A row with
            // is_confirmed_zero = true records «we checked: nothing carries»,
            // which is different from no row at all (unknown history).
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('cycle_year');
            $table->decimal('amount', 8, 2)->default(0); // signed; reduces capacity when negative
            $table->boolean('is_confirmed_zero')->default(false);
            $table->string('reason', 500);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'cycle_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_opening_entries');
    }
};
