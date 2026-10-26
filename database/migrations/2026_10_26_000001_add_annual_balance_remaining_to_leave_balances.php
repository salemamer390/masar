<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('leave_balances', 'annual_balance_remaining')) {
            Schema::table('leave_balances', function (Blueprint $table): void {
                $table->decimal('annual_balance_remaining', 8, 2)->default(0)->after('annual_used');
            });
        }

        DB::table('leave_balances')->update([
            'annual_balance_remaining' => DB::raw('GREATEST(annual_entitlement - annual_used, 0)'),
        ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('leave_balances', 'annual_balance_remaining')) {
            Schema::table('leave_balances', function (Blueprint $table): void {
                $table->dropColumn('annual_balance_remaining');
            });
        }
    }
};
