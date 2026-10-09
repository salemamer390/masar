<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Job;
use App\Models\MovementRecord;
use App\Models\MovementType;
use App\Services\MovementService;
use Illuminate\Http\Request;

class MovementController extends Controller
{
    public function index(Request $request)
    {
        $movements = MovementRecord::with(['employee.department', 'employee.job', 'movementType', 'oldDepartment', 'newDepartment', 'oldJob', 'newJob'])
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->filled('movement_type_id'), fn ($q) => $q->where('movement_type_id', $request->movement_type_id))
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('movement_date', '>=', $request->from_date))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('movement_date', '<=', $request->to_date))
            ->orderByDesc('movement_date')->paginate(25)->withQueryString();
        $employees = Employee::orderBy('full_name')->get();
        $movementTypes = MovementType::all();
        $departments = Department::where('is_active', 1)->get();
        $jobs = Job::where('is_active', 1)->get();

        return view('movements.index', compact('movements', 'employees', 'movementTypes', 'departments', 'jobs'));
    }

    public function store(Request $request, MovementService $movementService)
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'movement_type_id' => ['required', 'exists:movement_types,id'],
            'movement_date' => ['required', 'date'],
            'new_department_id' => ['nullable', 'exists:departments,id'],
            'new_job_id' => ['nullable', 'exists:jobs,id'],
            'new_salary' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $result = $movementService->apply($validated);
        $toast = 'تم تسجيل الحركة الوظيفية بنجاح.';
        if ($result['allowancesReset']) {
            $toast .= ' تمت تصفية البدلات لأنها تجاوزت الحد المسموح للراتب الجديد.';
        }

        return back()->with('toast', $toast);
    }
}
