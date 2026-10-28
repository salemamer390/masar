<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['name' => 'fingerprint-devices.view', 'display_name' => 'عرض أجهزة البصمة', 'module' => 'fingerprint', 'action' => 'view'],
            ['name' => 'fingerprint-devices.create', 'display_name' => 'إضافة أجهزة البصمة', 'module' => 'fingerprint', 'action' => 'create'],
            ['name' => 'fingerprint-devices.update', 'display_name' => 'تعديل أجهزة البصمة', 'module' => 'fingerprint', 'action' => 'update'],
            ['name' => 'fingerprint-devices.delete', 'display_name' => 'حذف أجهزة البصمة', 'module' => 'fingerprint', 'action' => 'delete'],
            ['name' => 'fingerprint-devices.test', 'display_name' => 'اختبار أجهزة البصمة', 'module' => 'fingerprint', 'action' => 'test'],
            ['name' => 'fingerprint-devices.sync', 'display_name' => 'مزامنة أجهزة البصمة', 'module' => 'fingerprint', 'action' => 'sync'],
            ['name' => 'fingerprint-sync-logs.view', 'display_name' => 'عرض سجلات مزامنة البصمة', 'module' => 'fingerprint', 'action' => 'view_logs'],
        ];
        foreach ($permissions as $permission) {
            $id = DB::table('permissions')->where('name', $permission['name'])->value('id');
            if (!$id) {
                $id = DB::table('permissions')->insertGetId($permission);
            }
            $roleIds = DB::table('roles')->whereIn('name', ['super_admin', 'company_manager', 'hr_manager', 'attendance_leaves'])->pluck('id');
            foreach ($roleIds as $roleId) {
                DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $id]);
            }
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')->whereIn('name', [
            'fingerprint-devices.view','fingerprint-devices.create','fingerprint-devices.update',
            'fingerprint-devices.delete','fingerprint-devices.test','fingerprint-devices.sync',
            'fingerprint-sync-logs.view',
        ])->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
