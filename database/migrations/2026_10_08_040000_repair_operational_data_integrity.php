<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('permissions') && Schema::hasTable('roles') && Schema::hasTable('role_permissions')) {
            DB::table('permissions')->updateOrInsert(
                ['name' => 'requests.delete'],
                [
                    'display_name' => 'Delete HR Requests',
                    'module' => 'requests',
                    'action' => 'delete',
                ],
            );

            $permissionId = DB::table('permissions')->where('name', 'requests.delete')->value('id');
            $roleIds = DB::table('roles')
                ->whereIn('name', ['super_admin', 'hr_manager', 'hr_specialist'])
                ->pluck('id');

            foreach ($roleIds as $roleId) {
                DB::table('role_permissions')->updateOrInsert([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ], []);
            }
        }

        if (Schema::hasTable('employees') && Schema::hasTable('employee_profile_details')) {
            $now = now();
            $employeeIds = DB::table('employees as e')
                ->leftJoin('employee_profile_details as p', 'p.employee_id', '=', 'e.id')
                ->whereNull('p.id')
                ->pluck('e.id');

            foreach ($employeeIds as $employeeId) {
                DB::table('employee_profile_details')->insert([
                    'employee_id' => $employeeId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // This migration repairs live data; it intentionally preserves it on rollback.
    }
};
