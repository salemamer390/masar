<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $roleIds = DB::table('roles')->where('name', 'hr_manager')->pluck('id');
        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['payroll.create', 'payroll.edit', 'payroll.approve', 'payroll.lock'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            foreach ($permissionIds as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $roleIds = DB::table('roles')->where('name', 'hr_manager')->pluck('id');
        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['payroll.create', 'payroll.edit', 'payroll.approve', 'payroll.lock'])
            ->pluck('id');

        DB::table('role_permissions')
            ->whereIn('role_id', $roleIds)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }
};
