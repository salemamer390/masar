<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE hr_holidays DROP INDEX hr_holidays_holiday_date_unique');
        Schema::table('hr_holidays', fn (Blueprint $table) => $table->unique(['company_id','holiday_date'], 'hr_holidays_company_date_unique'));
    }
    public function down(): void
    {
        Schema::table('hr_holidays', fn (Blueprint $table) => $table->dropUnique('hr_holidays_company_date_unique'));
        DB::statement('ALTER TABLE hr_holidays ADD UNIQUE hr_holidays_holiday_date_unique (holiday_date)');
    }
};
