<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $definitions = [
            'company_manager' => ['مدير الشركة', ['*']],
            'hr_manager' => ['مدير الموارد البشرية', ['dashboard.view', 'employees.view', 'employees.create', 'employees.edit', 'attendance.view', 'attendance.create', 'attendance.edit', 'leaves.view', 'leaves.create', 'leaves.edit', 'leaves.approve', 'documents.view', 'documents.upload', 'insurance.view', 'insurance.edit', 'penalties.view', 'penalties.create', 'penalties.edit', 'movements.view', 'movements.create', 'movements.edit', 'reports.view', 'reports.print', 'reports.export_excel', 'requests.view', 'requests.create', 'requests.approve', 'salary.view', 'payroll.view', 'payroll.export']],
            'hr_specialist' => ['أخصائي موارد بشرية', ['dashboard.view', 'employees.view', 'employees.create', 'employees.edit', 'attendance.view', 'attendance.create', 'attendance.edit', 'leaves.view', 'leaves.create', 'leaves.edit', 'documents.view', 'documents.upload', 'insurance.view', 'penalties.view', 'penalties.create', 'movements.view', 'movements.create', 'movements.edit', 'reports.view', 'requests.view', 'requests.create']],
            'recruitment' => ['مسؤول التوظيف', ['dashboard.view', 'employees.view', 'employees.create', 'employees.edit', 'documents.view', 'documents.upload', 'requests.view', 'requests.create', 'reports.view']],
            'payroll' => ['مسؤول الرواتب', ['dashboard.view', 'employees.view', 'salary.view', 'salary.create', 'salary.edit', 'payroll.view', 'payroll.create', 'payroll.edit', 'payroll.approve', 'payroll.export', 'reports.view', 'reports.print', 'reports.export_excel', 'insurance.view']],
            'attendance_leaves' => ['مسؤول الحضور والإجازات', ['dashboard.view', 'employees.view', 'attendance.view', 'attendance.create', 'attendance.edit', 'attendance.delete', 'leaves.view', 'leaves.create', 'leaves.edit', 'leaves.approve', 'leaves.delete', 'reports.view']],
            'department_manager' => ['مدير قسم', ['dashboard.view', 'employees.view', 'attendance.view', 'leaves.view', 'leaves.approve', 'requests.view', 'requests.create', 'requests.approve', 'reports.view']],
            'accountant' => ['المحاسب', ['dashboard.view', 'employees.view', 'salary.view', 'payroll.view', 'payroll.export', 'reports.view', 'reports.print', 'reports.export_excel']],
            'employee' => ['الموظف', ['dashboard.view', 'employees.view', 'attendance.view', 'leaves.view', 'leaves.create', 'documents.view', 'requests.view', 'requests.create']],
            'viewer' => ['مراجع للقراءة فقط', ['__read_only__']],
        ];

        $allPermissions = DB::table('permissions')->get(['id', 'name', 'action']);
        $permissionIds = $allPermissions->pluck('id', 'name');
        $readOnlyIds = $allPermissions->whereIn('action', ['view', 'print', 'export', 'export_excel'])->pluck('id')->all();
        $allPermissionIds = $allPermissions->pluck('id')->all();

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach ($definitions as $name => [$displayName, $permissionNames]) {
                $role = DB::table('roles')->where('company_id', $companyId)->where('name', $name)->first();
                if (!$role) {
                    $roleId = DB::table('roles')->insertGetId([
                        'company_id' => $companyId,
                        'name' => $name,
                        'display_name' => $displayName,
                        'description' => "دور افتراضي داخل الشركة: {$displayName}",
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $roleId = $role->id;
                }

                $ids = match ($permissionNames[0]) {
                    '*' => $allPermissionIds,
                    '__read_only__' => $readOnlyIds,
                    default => collect($permissionNames)->map(fn (string $permission) => $permissionIds[$permission] ?? null)->filter()->values()->all(),
                };

                DB::table('role_permissions')->where('role_id', $roleId)->delete();
                foreach ($ids as $permissionId) {
                    DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
                }
            }
        }
    }

    public function down(): void
    {
        // Existing roles and their assignments are intentionally preserved on rollback.
    }
};
