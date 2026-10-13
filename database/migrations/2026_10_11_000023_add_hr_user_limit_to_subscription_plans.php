<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { if (!Schema::hasColumn('subscription_plans', 'hr_user_limit')) Schema::table('subscription_plans', fn (Blueprint $table) => $table->unsignedInteger('hr_user_limit')->default(5)->after('employee_limit')); }
    public function down(): void { if (Schema::hasColumn('subscription_plans', 'hr_user_limit')) Schema::table('subscription_plans', fn (Blueprint $table) => $table->dropColumn('hr_user_limit')); }
};
