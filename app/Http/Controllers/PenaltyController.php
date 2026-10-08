<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Penalty;
use App\Models\PenaltyType;
use App\Services\PayrollPeriodGuard;
use Illuminate\Http\Request;

class PenaltyController extends Controller
{
    public function index()
    {
        $penalties = Penalty::with(['employee.department', 'employee.job', 'penaltyType'])
            ->orderBy('penalty_date', 'desc')
            ->paginate(25);

        $employees = Employee::orderBy('full_name')->get();
        $penaltyTypes = PenaltyType::where('is_active', 1)->get();

        return view('discipline.index', compact('penalties', 'employees', 'penaltyTypes'));
    }

    public function store(Request $request, PayrollPeriodGuard $periodGuard)
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'penalty_type_id' => ['nullable', 'exists:penalty_types,id'],
            'penalty_type_name' => ['nullable', 'string'],
            'penalty_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
            'details' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_days' => ['nullable', 'numeric', 'min:0'],
        ], [
            'employee_id.required' => 'الموظف مطلوب.',
            'penalty_date.required' => 'تاريخ الجزاء مطلوب.',
            'reason.required' => 'سبب المخالفة مطلوب.',
        ]);
        $periodGuard->ensureOpen($validated['penalty_date']);

        if (empty($validated['penalty_type_id'])) {
            $name = $request->input('penalty_type_name', 'لفت نظر');
            $type = PenaltyType::where('name', 'like', "%{$name}%")->first();
            $validated['penalty_type_id'] = $type?->id ?? 1;
        }

        $typeObj = PenaltyType::find($validated['penalty_type_id']);
        $title = $typeObj ? $typeObj->name : 'إجراء تأديبي';

        $penalty = Penalty::create([
            'employee_id' => $validated['employee_id'],
            'penalty_type_id' => $validated['penalty_type_id'],
            'penalty_date' => $validated['penalty_date'],
            'title' => $title,
            'reason' => $validated['reason'],
            'action' => $validated['details'] ?? $validated['reason'],
            'amount' => $validated['amount'] ?? 0,
            'deduction_days' => array_key_exists('deduction_days', $validated) ? $validated['deduction_days'] : $typeObj?->default_deduction_days,
            'status' => 'Pending',
            'issued_by' => auth()->id(),
        ]);

        $emp = Employee::find($validated['employee_id']);
        ActivityLog::record('penalty_create', "تم تسجيل إجراء تأديبي ({$title}) بحق الموظف ({$emp?->full_name}).", $penalty);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل الإجراء التأديبي',
                'penalty' => $penalty,
            ]);
        }

        return back()->with('toast', 'تم تسجيل الإجراء التأديبي');
    }

    public function close(Penalty $penalty, PayrollPeriodGuard $periodGuard)
    {
        $periodGuard->ensureOpen($penalty->penalty_date);
        $penalty->update([
            'status' => 'Approved',
            'closed_at' => now(),
        ]);

        ActivityLog::record('penalty_close', "تم إغلاق الإجراء التأديبي للموظف ({$penalty->employee?->full_name}).", $penalty);

        return back()->with('toast', 'تم إغلاق الإجراء التأديبي بنجاح');
    }

    public function destroy(Penalty $penalty, PayrollPeriodGuard $periodGuard)
    {
        $periodGuard->ensureOpen($penalty->penalty_date);
        $penalty->delete();
        return back()->with('toast', 'تم حذف السجل');
    }
}
