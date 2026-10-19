<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('request_types')) return;

        DB::table('request_types')->updateOrInsert(
            ['code' => 'overtime', 'company_id' => null],
            [
                'display_name' => 'طلب إضافي',
                'source_key' => 'overtime',
                'icon' => 'bi-clock-history',
                'icon_color' => '#2563EB',
                'icon_background' => '#EFF6FF',
                'metadata' => json_encode(['module' => 'attendance', 'approval_required' => true], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'sort_order' => 35,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        if (Schema::hasTable('request_types')) {
            DB::table('request_types')->where('code', 'overtime')->whereNull('company_id')->delete();
        }
    }
};
