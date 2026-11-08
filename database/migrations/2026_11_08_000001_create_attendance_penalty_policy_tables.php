<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per company. Off by default: nothing is deducted until a company
        // defines its own tiers and switches the policy on.
        if (!Schema::hasTable('attendance_penalty_policies')) {
            Schema::create('attendance_penalty_policies', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->unique();
                $table->boolean('is_enabled')->default(false);
                $table->date('effective_from')->nullable();
                // When false, a day with both lateness and early departure is charged only by the larger tier.
                $table->boolean('charge_both_same_day')->default(true);
                // JSON list of penalty type ids: a manual penalty of one of these types on the same date
                // replaces the automatic penalty for that date. Empty = manual penalties never replace it.
                $table->text('manual_replaces_types')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('attendance_penalty_tiers')) {
            Schema::create('attendance_penalty_tiers', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();
                $table->string('kind', 20); // late | early_leave
                $table->unsignedInteger('from_minutes');
                $table->unsignedInteger('to_minutes')->nullable();
                $table->unsignedInteger('occurrence_from')->default(1);
                $table->unsignedInteger('occurrence_to')->nullable();
                $table->decimal('deduction_days', 6, 2);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_penalty_tiers');
        Schema::dropIfExists('attendance_penalty_policies');
    }
};
