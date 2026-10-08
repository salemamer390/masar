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
    public function index()
    {
        $movements = MovementRecord::with(['employee.department', 'employee.job', 'movementType', 'oldDepartment', 'newDepartment', 'oldJob', 'newJob'])
            ->orderByDesc('movement_date')->paginate(25);
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
