<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('attendance', 'source')) {
            Schema::table('attendance', fn (Blueprint $table) => $table->string('source', 50)->default('manual')->after('status'));
        }
        if (!Schema::hasColumn('attendance', 'approval_status')) {
            Schema::table('attendance', fn (Blueprint $table) => $table->string('approval_status', 30)->default('Draft')->after('source'));
        }
        $hadApprovedBy = Schema::hasColumn('attendance', 'approved_by');
        if (!$hadApprovedBy) {
            Schema::table('attendance', fn (Blueprint $table) => $table->foreignId('approved_by')->nullable()->after('approval_status')->constrained('users')->nullOnDelete());
        }
        if (!Schema::hasColumn('attendance', 'approved_at')) {
            Schema::table('attendance', fn (Blueprint $table) => $table->timestamp('approved_at')->nullable()->after('approved_by'));
        }

        $indexExists = DB::table('information_schema.statistics')->where('table_schema', DB::raw('DATABASE()'))->where('table_name', 'attendance')->where('index_name', 'attendance_approval_status_index')->exists();
        if (!$indexExists) {
            Schema::table('attendance', fn (Blueprint $table) => $table->index('approval_status'));
        }

        if ($hadApprovedBy) {
            $foreignExists = DB::table('information_schema.key_column_usage')->where('table_schema', DB::raw('DATABASE()'))->where('table_name', 'attendance')->where('column_name', 'approved_by')->whereNotNull('referenced_table_name')->exists();
            if (!$foreignExists) {
                Schema::table('attendance', fn (Blueprint $table) => $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete());
            }
        }
    }

    public function down(): void
    {
        Schema::table('attendance', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropIndex(['approval_status']);
            $table->dropColumn(['source', 'approval_status', 'approved_by', 'approved_at']);
        });
    }
};
