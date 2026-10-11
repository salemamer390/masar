<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('leave_types')->where('policy_code', 'casual')->update(['max_days_per_request' => null]);
    }

    public function down(): void
    {
        DB::table('leave_types')->where('policy_code', 'casual')->update(['max_days_per_request' => 2]);
    }
};
