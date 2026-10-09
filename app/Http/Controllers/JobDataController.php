<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentType;
use App\Models\Job;
use Illuminate\Http\Request;

class JobDataController extends Controller
{
    public function index(Request $request)
    {
        $employees = Employee::with(['department', 'job', 'branch', 'employmentType'])
            ->when($request->filled('search'), fn ($q) => $q->where(function ($x) use ($request) { $x->where('full_name', 'like', '%'.$request->search.'%')->orWhere('employee_code', 'like', '%'.$request->search.'%'); }))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->department_id))
            ->when($request->filled('contract_status'), function ($q) use ($request) { return $request->contract_status === 'active' ? $q->where(function ($x) { $x->whereNull('contract_end_date')->orWhereDate('contract_end_date', '>=', today()); }) : $q->whereDate('contract_end_date', '<', today()); })
            ->orderBy('id', 'asc')
            ->get();

        $totalJobs = Job::where('is_active', 1)->count();
        $totalDepartments = Department::where('is_active', 1)->count();
        $totalBranches = Branch::where('is_active', 1)->count();
        $activeContracts = Employee::whereNotNull('contract_start_date')->count();
        $contractsDueSoon = Employee::whereBetween('contract_end_date', [today(), today()->copy()->addDays(30)])->count();

        $jobs = Job::where('is_active', 1)->get();
        $departments = Department::where('is_active', 1)->get();
        $branches = Branch::where('is_active', 1)->get();
        $employmentTypes = EmploymentType::all();

        return view('jobs.index', compact(
            'employees',
            'totalJobs',
            'totalDepartments',
            'totalBranches',
            'activeContracts',
            'contractsDueSoon',
            'jobs',
            'departments',
            'branches',
            'employmentTypes'
        ));
    }
}
