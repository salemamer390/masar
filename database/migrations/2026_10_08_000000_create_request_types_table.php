<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('display_name', 150);
            $table->string('source_key', 80)->index();
            $table->string('icon', 100)->default('bi-clipboard-check');
            $table->string('icon_color', 20)->default('#64748B');
            $table->string('icon_background', 20)->default('#F1F5F9');
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('request_types')->updateOrInsert(
            ['code' => 'leave'],
            [
                'display_name' => 'طلب إجازة',
                'source_key' => 'leave',
                'icon' => 'bi-calendar-check',
                'icon_color' => '#EF4444',
                'icon_background' => '#FEF2F2',
                'metadata' => json_encode(['module' => 'leaves'], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'sort_order' => 10,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('request_types');
    }
};
