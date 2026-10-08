<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employees') && ! Schema::hasColumn('employees', 'pre_leave_status_id')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->unsignedBigInteger('pre_leave_status_id')->nullable()->index()->after('status_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('employees') && Schema::hasColumn('employees', 'pre_leave_status_id')) {
            Schema::table('employees', function (Blueprint $table): void {
                $table->dropColumn('pre_leave_status_id');
            });
        }
    }
};
