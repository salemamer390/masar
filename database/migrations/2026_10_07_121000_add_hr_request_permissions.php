<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('roles') || !Schema::hasTable('role_permissions')) {
            return;
        }

        $permissions = [
            ['name' => 'requests.view', 'display_name' => 'View HR Requests', 'module' => 'requests', 'action' => 'view'],
            ['name' => 'requests.create', 'display_name' => 'Create HR Requests', 'module' => 'requests', 'action' => 'create'],
            ['name' => 'requests.approve', 'display_name' => 'Approve HR Requests', 'module' => 'requests', 'action' => 'approve'],
            ['name' => 'requests.delete', 'display_name' => 'Delete HR Requests', 'module' => 'requests', 'action' => 'delete'],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name']],
                $permission,
            );
        }

        $rolePermissions = [
            'super_admin' => ['requests.view', 'requests.create', 'requests.approve', 'requests.delete'],
            'hr_manager' => ['requests.view', 'requests.create', 'requests.approve', 'requests.delete'],
            'hr_specialist' => ['requests.view', 'requests.create', 'requests.approve', 'requests.delete'],
            'manager' => ['requests.view', 'requests.create', 'requests.approve'],
            'employee' => ['requests.view', 'requests.create'],
            'viewer' => ['requests.view'],
        ];

        foreach ($rolePermissions as $roleName => $permissionNames) {
            $roleId = DB::table('roles')->where('name', $roleName)->value('id');
            if (!$roleId) {
                continue;
            }

            foreach ($permissionNames as $permissionName) {
                $permissionId = DB::table('permissions')->where('name', $permissionName)->value('id');
                if (!$permissionId) {
                    continue;
                }

                DB::table('role_permissions')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ], []);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions')) {
            return;
        }

        $ids = DB::table('permissions')->whereIn('name', [
            'requests.view', 'requests.create', 'requests.approve', 'requests.delete',
        ])->pluck('id');

        if (Schema::hasTable('role_permissions') && $ids->isNotEmpty()) {
            DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        }

        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
