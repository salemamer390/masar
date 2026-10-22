<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('email', 'amer.salem@tazamun.local')
            ->update(['company_id' => 1, 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', 'amer.salem@tazamun.local')
            ->update(['company_id' => null, 'updated_at' => now()]);
    }
};
