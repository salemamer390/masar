<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fresh installs never received the reference data the application requires:
 * employee statuses (employees.status_id is a foreign key into
 * employee_statuses) and the permission catalog the roles migration attaches.
 * The data previously existed only in the optional TazamunSeeder, so employee
 * creation and permission checks broke on new installations. Seed it here,
 * idempotently, before the default-role backfill runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('employee_statuses')) {
            foreach (['Active', 'Leave', 'Suspended', 'Terminated', 'Pending'] as $name) {
                DB::table('employee_statuses')->insertOrIgnore(['name' => $name]);
            }
        }

        if (Schema::hasTable('permissions')) {
            $permissions = [
                ['dashboard.view', 'View Dashboard', 'dashboard', 'view'],
                ['employees.view', 'View Employees', 'employees', 'view'], ['employees.create', 'Create Employees', 'employees', 'create'], ['employees.edit', 'Edit Employees', 'employees', 'edit'], ['employees.import', 'Import Employees', 'employees', 'import'],
                ['attendance.view', 'View Attendance', 'attendance', 'view'], ['attendance.create', 'Create Attendance', 'attendance', 'create'], ['attendance.edit', 'Edit Attendance', 'attendance', 'edit'], ['attendance.delete', 'Delete Attendance', 'attendance', 'delete'],
                ['requests.view', 'View HR Requests', 'requests', 'view'], ['requests.create', 'Create HR Requests', 'requests', 'create'], ['requests.approve', 'Approve HR Requests', 'requests', 'approve'], ['requests.delete', 'Delete HR Requests', 'requests', 'delete'],
                ['leaves.view', 'View Leaves', 'leaves', 'view'], ['leaves.create', 'Create Leave', 'leaves', 'create'], ['leaves.edit', 'Edit Leave', 'leaves', 'edit'], ['leaves.approve', 'Approve Leave', 'leaves', 'approve'], ['leaves.delete', 'Delete Leave', 'leaves', 'delete'],
                ['documents.view', 'View Documents', 'documents', 'view'], ['documents.upload', 'Upload Documents', 'documents', 'upload'], ['documents.replace', 'Replace Documents', 'documents', 'replace'], ['documents.delete', 'Delete Documents', 'documents', 'delete'],
                ['salary.view', 'View Salary', 'salary', 'view'], ['salary.create', 'Create Salary', 'salary', 'create'], ['salary.edit', 'Edit Salary', 'salary', 'edit'], ['salary.delete', 'Delete Salary', 'salary', 'delete'],
                ['payroll.view', 'View Payroll', 'payroll', 'view'], ['payroll.create', 'Create Payroll', 'payroll', 'create'], ['payroll.edit', 'Edit Payroll', 'payroll', 'edit'], ['payroll.export', 'Export Payroll', 'payroll', 'export'],
                ['insurance.view', 'View Insurance', 'insurance', 'view'], ['insurance.create', 'Create Insurance', 'insurance', 'create'], ['insurance.edit', 'Edit Insurance', 'insurance', 'edit'],
                ['penalties.view', 'View Penalties', 'penalties', 'view'], ['penalties.create', 'Create Penalties', 'penalties', 'create'], ['penalties.edit', 'Edit Penalties', 'penalties', 'edit'], ['penalties.delete', 'Delete Penalties', 'penalties', 'delete'],
                ['movements.view', 'View Movements', 'movements', 'view'], ['movements.create', 'Create Movements', 'movements', 'create'],
                ['reports.view', 'View Reports', 'reports', 'view'], ['reports.print', 'Print Reports', 'reports', 'print'], ['reports.export_excel', 'Export Reports to Excel', 'reports', 'export_excel'],
                ['users.view', 'View Users', 'users', 'view'], ['users.create', 'Create Users', 'users', 'create'], ['users.edit', 'Edit Users', 'users', 'edit'],
                ['roles.view', 'View Roles', 'roles', 'view'], ['roles.create', 'Create Roles', 'roles', 'create'], ['roles.edit', 'Edit Roles', 'roles', 'edit'], ['roles.delete', 'Delete Roles', 'roles', 'delete'],
            ];
            foreach ($permissions as [$name, $display, $module, $action]) {
                DB::table('permissions')->insertOrIgnore([
                    'name' => $name,
                    'display_name' => $display,
                    'module' => $module,
                    'action' => $action,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Never remove reference data on rollback.
    }
};
