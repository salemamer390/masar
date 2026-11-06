<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fresh installs never created the payroll calculation tables: the original
 * create migration was written against an existing database and later
 * removed, so payroll settings reads crashed on any new installation.
 * Recreate the three tables in their original (pre company-scope) shape,
 * including the legacy unique index names that later migrations drop and
 * replace. On databases that already have the tables this is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payroll_calculation_settings')) {
            Schema::create('payroll_calculation_settings', function (Blueprint $table) {
                $table->id();
                $table->string('setting_group', 50);
                $table->string('setting_key', 100);
                $table->decimal('setting_value', 14, 4)->nullable();
                $table->date('effective_from')->nullable();
                $table->string('value_type', 20)->default('number');
                $table->string('unit', 50)->nullable();
                $table->string('label')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_editable')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['setting_group', 'setting_key'], 'uq_payroll_calculation_setting');
            });
        }

        if (!Schema::hasTable('payroll_allowance_settings')) {
            Schema::create('payroll_allowance_settings', function (Blueprint $table) {
                $table->id();
                $table->string('allowance_key', 50);
                $table->string('allowance_name');
                $table->decimal('percentage', 8, 4)->default(0);
                $table->string('calculation_basis', 50)->default('allowance_pool');
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique('allowance_key', 'uq_allowance_key');
            });
        }

        if (!Schema::hasTable('payroll_calculation_rules')) {
            Schema::create('payroll_calculation_rules', function (Blueprint $table) {
                $table->id();
                $table->string('rule_group', 50);
                $table->string('rule_key', 100);
                $table->string('rule_value')->nullable();
                $table->string('label')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_editable')->default(true);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['rule_group', 'rule_key'], 'uq_payroll_calculation_rule');
            });
        }
    }

    public function down(): void
    {
        // Never drop data-bearing tables on rollback.
    }
};
