<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration
{
    public function up(): void
    {
        $companyId = DB::table('companies')->value('id');
        Schema::table('users', function (Blueprint $table): void { $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete(); });
        Schema::table('employees', function (Blueprint $table): void { $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete(); });
        if ($companyId) { DB::table('users')->whereNull('company_id')->update(['company_id'=>$companyId]); DB::table('employees')->whereNull('company_id')->update(['company_id'=>$companyId]); }
    }
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void { $table->dropForeign(['company_id']); $table->dropColumn('company_id'); });
        Schema::table('employees', function (Blueprint $table): void { $table->dropForeign(['company_id']); $table->dropColumn('company_id'); });
    }
};
