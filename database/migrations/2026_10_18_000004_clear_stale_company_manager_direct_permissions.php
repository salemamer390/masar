<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $managerUserIds = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'company_manager')
            ->pluck('users.id');

        if ($managerUserIds->isNotEmpty()) {
            DB::table('user_permissions')->whereIn('user_id', $managerUserIds)->delete();
        }
    }

    public function down(): void
    {
        // Direct permission rows were stale overrides and cannot be restored safely.
    }
};
