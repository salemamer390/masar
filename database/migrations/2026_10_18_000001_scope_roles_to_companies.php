<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('roles', 'company_id')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
            });
        }

        $indexes = collect(DB::select('SHOW INDEX FROM roles'))->pluck('Key_name')->unique();
        foreach (['roles_name_unique', 'name'] as $index) {
            if ($indexes->contains($index)) {
                DB::statement("ALTER TABLE roles DROP INDEX `{$index}`");
                break;
            }
        }
        if (!$indexes->contains('roles_company_name_unique')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->unique(['company_id', 'name'], 'roles_company_name_unique');
            });
        }

    }

    public function down(): void
    {
        $indexes = collect(DB::select('SHOW INDEX FROM roles'))->pluck('Key_name')->unique();
        if ($indexes->contains('roles_company_name_unique')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->dropUnique('roles_company_name_unique');
            });
        }
        if (Schema::hasColumn('roles', 'company_id')) {
            Schema::table('roles', function (Blueprint $table): void {
                $table->dropForeign(['company_id']);
                $table->dropColumn('company_id');
                $table->unique('name', 'roles_name_unique');
            });
        }
    }
};
