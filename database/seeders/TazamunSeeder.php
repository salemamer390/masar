<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TazamunSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        foreach (['Human Resources','Marketing','Design','Sales','Administration','Customer Service','Accounts','IT'] as $name) DB::table('departments')->insert(['name'=>$name]);
        DB::table('jobs')->insert([
            ['title'=>'HR Manager','job_code'=>null], ['title'=>'Senior Media Buyer','job_code'=>'243105'], ['title'=>'Content Creator','job_code'=>'264101'],
            ['title'=>'Graphic Designer','job_code'=>null], ['title'=>'Video Editor','job_code'=>null], ['title'=>'Marketing Specialist','job_code'=>null],
            ['title'=>'Social Media Specialist','job_code'=>null], ['title'=>'Account Manager','job_code'=>null],
        ]);
        foreach (['Head Office','Nasr City','New Cairo','Sheikh Zayed'] as $name) DB::table('branches')->insert(['name'=>$name]);
        foreach (['Active','Leave','Suspended','Terminated','Pending'] as $name) DB::table('employee_statuses')->insert(['name'=>$name]);
        foreach (['Full Time','Part Time','Fixed Contract','Internship','Freelance'] as $name) DB::table('employment_types')->insert(['name'=>$name]);
        DB::table('leave_types')->insert([
            ['name'=>'Annual','default_days'=>21], ['name'=>'Casual','default_days'=>6], ['name'=>'Sick','default_days'=>null], ['name'=>'Unpaid','default_days'=>null],
            ['name'=>'Mission','default_days'=>null], ['name'=>'Maternity','default_days'=>null], ['name'=>'Bereavement','default_days'=>null],
        ]);
        foreach (['Verbal Warning','Written Warning','Day Deduction','Salary Deduction','Notice','Suspension'] as $name) DB::table('penalty_types')->insert(['name'=>$name]);
        foreach (['Hire','Department Transfer','Promotion','Salary Adjustment','Branch Transfer','Job Title Change'] as $name) DB::table('movement_types')->insert(['name'=>$name]);
        foreach (['Bank','Cash','Wallet'] as $name) DB::table('payment_methods')->insert(['name'=>$name]);

        DB::table('roles')->insert([
            ['name'=>'super_admin','display_name'=>'Super Admin','description'=>'Full system access'],
            ['name'=>'hr_manager','display_name'=>'HR Manager','description'=>'HR management access'],
            ['name'=>'hr_specialist','display_name'=>'HR Specialist','description'=>'HR operational access'],
            ['name'=>'payroll','display_name'=>'Payroll','description'=>'Payroll and salary access'],
            ['name'=>'manager','display_name'=>'Manager','description'=>'Access to team data'],
            ['name'=>'employee','display_name'=>'Employee','description'=>'Personal employee access'],
            ['name'=>'viewer','display_name'=>'Viewer','description'=>'Read-only access to all system data'],
        ]);
        $permissions = [
            ['dashboard.view','View Dashboard','dashboard','view'],
            ['employees.view','View Employees','employees','view'],['employees.create','Create Employees','employees','create'],['employees.edit','Edit Employees','employees','edit'],['employees.delete','Delete Employees','employees','delete'],['employees.import','Import Employees','employees','import'],
            ['attendance.view','View Attendance','attendance','view'],['attendance.create','Create Attendance','attendance','create'],['attendance.edit','Edit Attendance','attendance','edit'],['attendance.delete','Delete Attendance','attendance','delete'],
            ['requests.view','View HR Requests','requests','view'],['requests.create','Create HR Requests','requests','create'],['requests.approve','Approve HR Requests','requests','approve'],['requests.delete','Delete HR Requests','requests','delete'],
            ['leaves.view','View Leaves','leaves','view'],['leaves.create','Create Leave','leaves','create'],['leaves.edit','Edit Leave','leaves','edit'],['leaves.approve','Approve Leave','leaves','approve'],['leaves.delete','Delete Leave','leaves','delete'],
            ['documents.view','View Documents','documents','view'],['documents.upload','Upload Documents','documents','upload'],['documents.replace','Replace Documents','documents','replace'],['documents.delete','Delete Documents','documents','delete'],
            ['salary.view','View Salary','salary','view'],['salary.create','Create Salary','salary','create'],['salary.edit','Edit Salary','salary','edit'],['salary.delete','Delete Salary','salary','delete'],
            ['payroll.view','View Payroll','payroll','view'],['payroll.create','Create Payroll','payroll','create'],['payroll.edit','Edit Payroll','payroll','edit'],['payroll.export','Export Payroll','payroll','export'],
            ['insurance.view','View Insurance','insurance','view'],['insurance.create','Create Insurance','insurance','create'],['insurance.edit','Edit Insurance','insurance','edit'],['insurance.delete','Delete Insurance','insurance','delete'],
            ['penalties.view','View Penalties','penalties','view'],['penalties.create','Create Penalties','penalties','create'],['penalties.edit','Edit Penalties','penalties','edit'],['penalties.delete','Delete Penalties','penalties','delete'],
            ['movements.view','View Movements','movements','view'],['movements.create','Create Movements','movements','create'],['movements.edit','Edit Movements','movements','edit'],['movements.delete','Delete Movements','movements','delete'],
            ['reports.view','View Reports','reports','view'],['reports.print','Print Reports','reports','print'],['reports.export_excel','Export Reports to Excel','reports','export_excel'],
            ['users.view','View Users','users','view'],['users.create','Create Users','users','create'],['users.edit','Edit Users','users','edit'],['users.delete','Delete Users','users','delete'],
            ['roles.view','View Roles','roles','view'],['roles.create','Create Roles','roles','create'],['roles.edit','Edit Roles','roles','edit'],['roles.delete','Delete Roles','roles','delete'],
        ];
        foreach ($permissions as [$name,$display,$module,$action]) DB::table('permissions')->insert(['name'=>$name,'display_name'=>$display,'module'=>$module,'action'=>$action]);
        $roleIds=DB::table('roles')->pluck('id','name');
        $all=DB::table('permissions')->get();
        foreach ($all as $p) DB::table('role_permissions')->insert(['role_id'=>$roleIds['super_admin'],'permission_id'=>$p->id]);
        foreach ($all->whereIn('action',['view','print','export','export_excel']) as $p) DB::table('role_permissions')->insert(['role_id'=>$roleIds['viewer'],'permission_id'=>$p->id]);

        $hrManager = DB::table('permissions')->where(function($q){
            $q->whereIn('module',['dashboard','employees','attendance','leaves','documents','insurance','penalties','movements','reports','requests'])
              ->orWhere(fn($q)=>$q->where('module','salary')->where('action','view'))
              ->orWhere(fn($q)=>$q->where('module','payroll')->whereIn('action',['view','export']));
        })->get();
        foreach ($hrManager as $p) DB::table('role_permissions')->insert(['role_id'=>$roleIds['hr_manager'],'permission_id'=>$p->id]);
        foreach (DB::table('permissions')->whereIn('module',['dashboard','employees','attendance','leaves','documents','insurance','penalties','movements','reports','requests'])->where('action','<>','delete')->get() as $p) DB::table('role_permissions')->insert(['role_id'=>$roleIds['hr_specialist'],'permission_id'=>$p->id]);
        foreach (DB::table('permissions')->whereIn('module',['dashboard','payroll','salary','reports'])->where('action','<>','delete')->get() as $p) DB::table('role_permissions')->insert(['role_id'=>$roleIds['payroll'],'permission_id'=>$p->id]);
        foreach (DB::table('permissions')->whereIn('action',['view','print','export_excel'])->whereIn('module',['dashboard','employees','attendance','leaves','reports'])->get() as $p) DB::table('role_permissions')->insert(['role_id'=>$roleIds['manager'],'permission_id'=>$p->id]);
        foreach (DB::table('permissions')->whereIn('name',['requests.view','requests.create','requests.approve'])->get() as $p) DB::table('role_permissions')->insert(['role_id'=>$roleIds['manager'],'permission_id'=>$p->id]);
        foreach (DB::table('permissions')->where('action','view')->whereIn('module',['dashboard','employees','attendance','leaves','documents'])->get() as $p) DB::table('role_permissions')->insert(['role_id'=>$roleIds['employee'],'permission_id'=>$p->id]);
        foreach (DB::table('permissions')->whereIn('name',['requests.view','requests.create'])->get() as $p) DB::table('role_permissions')->insert(['role_id'=>$roleIds['employee'],'permission_id'=>$p->id]);

        $employeeId=DB::table('employees')->insertGetId([
            'employee_code'=>'101','first_name'=>'Amer','middle_name'=>'Salem','last_name'=>null,'full_name'=>'Amer Salem','email'=>'amer.salem@tazamun.local',
            'department_id'=>DB::table('departments')->where('name','Human Resources')->value('id'), 'job_id'=>DB::table('jobs')->where('title','HR Manager')->value('id'),
            'branch_id'=>DB::table('branches')->where('name','Head Office')->value('id'), 'employment_type_id'=>DB::table('employment_types')->where('name','Full Time')->value('id'),
            'status_id'=>DB::table('employee_statuses')->where('name','Active')->value('id'), 'hire_date'=>'2026-07-29','contract_start_date'=>'2026-07-29','contract_end_date'=>'2027-07-28','created_at'=>$now,'updated_at'=>$now,
        ]);
        DB::table('users')->insert(['name'=>'Amer Salem','email'=>'amer.salem@tazamun.local','password'=>Hash::make('password'),'role_id'=>$roleIds['super_admin'],'employee_id'=>$employeeId,'is_active'=>true,'created_at'=>$now,'updated_at'=>$now]);
        $calculator = new \App\Services\PayrollCalculator();
        $structure = $calculator->structure(['salary_type'=>'net','salary_input'=>20000]);
        DB::table('salaries')->insert(array_merge([
            'employee_id'=>$employeeId,'effective_from'=>'2026-07-29','salary_type'=>'net','salary_input'=>20000,
            'deductions'=>0,'fixed_deductions'=>0,'status'=>'Active','created_at'=>$now,'updated_at'=>$now,
        ], $calculator->persistFields($structure)));
    }
}
