<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('hr_requests', 'title')) {
            Schema::table('hr_requests', function (Blueprint $table): void {
                $table->string('title', 200)->nullable()->after('request_type_id');
            });
        }

        // Give existing rows a usable title after restoring the column.
        DB::statement("UPDATE hr_requests SET title = COALESCE(NULLIF(title, ''), request_type) WHERE title IS NULL OR title = ''");
    }

    public function down(): void
    {
        if (Schema::hasColumn('hr_requests', 'title')) {
            Schema::table('hr_requests', function (Blueprint $table): void {
                $table->dropColumn('title');
            });
        }
    }
};
