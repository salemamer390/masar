<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // اسم الجدول الفعلي في المشروع هو attendance بالمفرد، وليس attendances.
        if (!Schema::hasTable('attendance') || Schema::hasColumn('attendance', 'company_id')) return;

        Schema::table('attendance', function (Blueprint $table): void {
            $table->foreignId('company_id')->nullable()->after('id')->constrained('companies')->nullOnDelete();
        });

        DB::statement(
            'UPDATE attendance a INNER JOIN employees e ON e.id = a.employee_id
             SET a.company_id = e.company_id WHERE a.company_id IS NULL'
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('attendance') || !Schema::hasColumn('attendance', 'company_id')) return;
        Schema::table('attendance', function (Blueprint $table): void {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
