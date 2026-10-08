<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['payroll.approve', 'Approve Payroll Runs', 'payroll', 'approve'],
            ['payroll.lock', 'Lock Payroll Runs', 'payroll', 'lock'],
        ] as [$name, $displayName, $module, $action]) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $name],
                ['display_name' => $displayName, 'module' => $module, 'action' => $action]
            );
            $permissionId = DB::table('permissions')->where('name', $name)->value('id');
            foreach (DB::table('roles')->whereIn('name', ['super_admin', 'payroll'])->pluck('id') as $roleId) {
                DB::table('role_permissions')->updateOrInsert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        foreach (['payroll.approve', 'payroll.lock'] as $name) {
            $permissionId = DB::table('permissions')->where('name', $name)->value('id');
            if ($permissionId) {
                DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
                DB::table('permissions')->where('id', $permissionId)->delete();
            }
        }
    }
};
