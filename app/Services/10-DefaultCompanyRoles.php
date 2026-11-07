<?php

namespace App\Services;

use App\Models\Permission;
use App\Models\Role;

/**
 * Default role catalogue created with every company.
 *
 * company_manager is the owner role with every permission. The other roles are
 * ready-made job profiles the manager can hand out to employees.
 */
class DefaultCompanyRoles
{
    /** @return array<string, array{0:string,1:string,2:array<int,string>}> name => [display, description, permissions] */
    public static function definitions(): array
    {
        $read = ['view', 'print', 'export', 'export_excel'];

        return [
            'hr_manager' => ['مدير الموارد البشرية', 'يدير شؤون الموظفين والحضور والإجازات والجزاءات ويعتمد الطلبات، ويطّلع على الرواتب.', ['dashboard.view', 'employees.view', 'employees.create', 'employees.edit', 'attendance.view', 'attendance.create', 'attendance.edit', 'leaves.view', 'leaves.create', 'leaves.edit', 'leaves.approve', 'documents.view', 'documents.upload', 'insurance.view', 'insurance.edit', 'penalties.view', 'penalties.create', 'penalties.edit', 'movements.view', 'movements.create', 'movements.edit', 'reports.view', 'reports.print', 'reports.export_excel', 'requests.view', 'requests.create', 'requests.approve', 'salary.view', 'payroll.view', 'payroll.export', 'settlements.view', 'settlements.create', 'settlements.approve']],
            'hr_specialist' => ['أخصائي موارد بشرية', 'تشغيل يومي لشؤون الموظفين والحضور والإجازات بدون اعتماد.', ['dashboard.view', 'employees.view', 'employees.create', 'employees.edit', 'attendance.view', 'attendance.create', 'attendance.edit', 'leaves.view', 'leaves.create', 'leaves.edit', 'documents.view', 'documents.upload', 'insurance.view', 'penalties.view', 'penalties.create', 'movements.view', 'movements.create', 'movements.edit', 'reports.view', 'requests.view', 'requests.create']],
            'recruitment' => ['مسؤول التوظيف', 'إضافة الموظفين الجدد ورفع مستنداتهم.', ['dashboard.view', 'employees.view', 'employees.create', 'employees.edit', 'documents.view', 'documents.upload', 'requests.view', 'requests.create', 'reports.view']],
            'payroll' => ['مسؤول الرواتب', 'يجهّز هياكل الرواتب ويراجع المسير ويصدّره، بدون صلاحية إعدادات الحساب أو الحذف.', ['dashboard.view', 'employees.view', 'salary.view', 'salary.create', 'salary.edit', 'payroll.view', 'payroll.create', 'payroll.edit', 'payroll.export', 'settlements.view', 'settlements.create', 'requests.view', 'requests.create', 'reports.view', 'reports.print', 'reports.export_excel', 'insurance.view']],
            'payroll_manager' => ['مدير الرواتب', 'مسؤول كامل عن الرواتب: الهياكل والمسير والإعدادات والاعتماد والقفل، بدون حذف المسير.', ['dashboard.view', 'employees.view', 'salary.view', 'salary.create', 'salary.edit', 'payroll.view', 'payroll.create', 'payroll.edit', 'payroll.approve', 'payroll.lock', 'payroll.export', 'payroll.settings', 'settlements.view', 'settlements.create', 'settlements.approve', 'requests.view', 'requests.create', 'reports.view', 'reports.print', 'reports.export_excel', 'insurance.view']],
            'attendance_leaves' => ['مسؤول الحضور والإجازات', 'رفع الحضور من البصمة وإدخاله يدويًا وإدارة الإجازات.', ['dashboard.view', 'employees.view', 'attendance.view', 'attendance.create', 'attendance.edit', 'attendance.delete', 'leaves.view', 'leaves.create', 'leaves.edit', 'leaves.approve', 'leaves.delete', 'reports.view']],
            'department_manager' => ['مدير قسم', 'يعتمد إجازات وطلبات فريقه ويطّلع على حضورهم.', ['dashboard.view', 'employees.view', 'attendance.view', 'leaves.view', 'leaves.approve', 'requests.view', 'requests.create', 'requests.approve', 'reports.view']],
            'accountant' => ['المحاسب', 'اطلاع وتصدير على الرواتب والتقارير فقط.', ['dashboard.view', 'employees.view', 'salary.view', 'payroll.view', 'payroll.export', 'reports.view', 'reports.print', 'reports.export_excel']],
            'employee' => ['الموظف', 'بيانات الموظف الشخصية وطلباته.', ['dashboard.view', 'employees.view', 'attendance.view', 'leaves.view', 'leaves.create', 'documents.view', 'requests.view', 'requests.create']],
            'viewer' => ['مراجع للقراءة فقط', 'اطلاع فقط على كل الشاشات بدون أي تعديل.', Permission::query()->whereIn('action', $read)->pluck('name')->all()],
        ];
    }

    /**
     * Create the company manager role and every missing default role.
     * Existing roles keep the permissions the manager already customised
     * unless $syncExisting is true (used for brand-new companies).
     */
    public function ensureForCompany(int $companyId, bool $syncExisting = false): void
    {
        $allPermissionIds = Permission::query()->pluck('id')->all();

        $manager = Role::withoutGlobalScopes()->firstOrCreate(
            ['company_id' => $companyId, 'name' => 'company_manager'],
            ['display_name' => 'مدير الشركة', 'description' => 'صاحب الشركة: كل الصلاحيات داخل شركته.'],
        );
        $manager->permissions()->sync($allPermissionIds);

        $definitions = self::definitions();
        $permissionIds = Permission::query()->pluck('id', 'name');

        foreach ($definitions as $name => [$displayName, $description, $permissions]) {
            $role = Role::withoutGlobalScopes()->where('company_id', $companyId)->where('name', $name)->first();
            $created = false;
            if (!$role) {
                $role = Role::withoutGlobalScopes()->create([
                    'company_id' => $companyId, 'name' => $name,
                    'display_name' => $displayName, 'description' => $description,
                ]);
                $created = true;
            }
            if ($created || $syncExisting) {
                $role->permissions()->sync(
                    collect($permissions)->map(fn (string $permission) => $permissionIds[$permission] ?? null)->filter()->values()->all()
                );
            }
        }
    }
}
