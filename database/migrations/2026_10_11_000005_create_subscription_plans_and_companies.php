<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->unsignedInteger('employee_limit');
            $table->decimal('price', 12, 2)->default(0);
            $table->string('billing_period', 20)->default('monthly');
            $table->json('features')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
        Schema::create('companies', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::create('company_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_plan_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('trial');
            $table->date('starts_at');
            $table->date('ends_at')->nullable();
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->json('feature_overrides')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
        foreach ([20, 50, 100, 300, 700, 1000] as $limit) {
            DB::table('subscription_plans')->insert(['name' => "حتى {$limit} موظف", 'slug' => "up-to-{$limit}", 'employee_limit' => $limit, 'features' => json_encode([]), 'created_at' => now(), 'updated_at' => now()]);
        }
    }
    public function down(): void
    {
        Schema::dropIfExists('company_subscriptions');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('subscription_plans');
    }
};
