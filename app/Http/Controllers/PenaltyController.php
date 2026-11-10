<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\Penalty;
use App\Models\PenaltyType;
use App\Services\PayrollPeriodGuard;
use App\Services\PayrollRunService;
use App\Services\HrOperationalSettingsService;
use Illuminate\Http\Request;

class PenaltyController extends Controller
{
    public function index(Request $request, HrOperationalSettingsService $hrSettings)
    {
        $period = $hrSettings->payrollPeriod(today()->copy());
        $periodStart = $period['start']->toDateString();
        $periodEnd = $period['end']->toDateString();
        $penalties = Penalty::with(['employee.department', 'employee.job', 'penaltyType'])
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->filled('department_id'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))
            ->when($request->filled('status'), fn ($q) => $request->status === 'Approved' ? $q->effective() : $q->where('status', $request->status))
            ->when($request->filled('from_date'), fn ($q) => $q->whereDate('penalty_date', '>=', $request->from_date))
            ->when($request->filled('to_date'), fn ($q) => $q->whereDate('penalty_date', '<=', $request->to_date))
            ->when(!$request->filled('from_date'), fn ($q) => $q->whereDate('penalty_date', '>=', $periodStart))
            ->when(!$request->filled('to_date'), fn ($q) => $q->whereDate('penalty_date', '<=', $periodEnd))
            ->orderByDesc('penalty_date')->orderByDesc('id')
            ->paginate(in_array((int) $request->input('per_page', 25), [25, 50, 100, 200], true) ? (int) $request->input('per_page', 25) : 25)->withQueryString();

        $employees = Employee::orderBy('full_name')->get();
        $penaltyTypes = PenaltyType::where('is_active', 1)->get();

        $departments = \App\Models\Department::where('is_active', 1)->orderBy('name')->get();
        return view('discipline.index', compact('penalties', 'employees', 'penaltyTypes', 'departments', 'periodStart', 'periodEnd'));
    }

    public function store(Request $request, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns)
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'penalty_type_id' => ['nullable', new \App\Rules\VisibleModel(\App\Models\PenaltyType::class)],
            'penalty_type_name' => ['nullable', 'string'],
            'penalty_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
            'details' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_days' => ['nullable', 'numeric', 'min:0'],
        ], [
            'employee_id.required' => __('الموظف مطلوب.'),
            'penalty_date.required' => __('تاريخ الجزاء مطلوب.'),
            'reason.required' => __('سبب المخالفة مطلوب.'),
        ]);
        $periodGuard->ensureOpen($validated['penalty_date']);
        $employee = Employee::findOrFail($validated['employee_id']);

        if (empty($validated['penalty_type_id'])) {
            $name = $request->input('penalty_type_name', 'لفت نظر');
            $type = PenaltyType::where('name', 'like', "%{$name}%")->first();
            if (!$type) {
                // No usable penalty type: report it clearly instead of falling back
                // to a hard-coded id that may not exist (foreign-key failure).
                $message = __('لا يوجد نوع جزاء مطابق. أضف نوع جزاء من البيانات الأساسية (قوائم الجزاءات) ثم أعد المحاولة.');
                return $request->wantsJson()
                    ? response()->json(['message' => $message, 'errors' => ['penalty_type_id' => [$message]]], 422)
                    : back()->withErrors(['penalty_type_id' => $message])->withInput();
            }
            $validated['penalty_type_id'] = $type->id;
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
            // Penalties affect payroll immediately; there is no separate
            // approval step in the HR request workflow.
            'status' => 'Approved',
            'issued_by' => auth()->id(),
        ]);
        $payrollRuns->refreshEmployeeInOpenDrafts((int) $penalty->employee_id);

        $emp = $employee;
        ActivityLog::record('penalty_create', "تم تسجيل إجراء تأديبي ({$title}) بحق الموظف ({$emp?->full_name}).", $penalty);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('تم تسجيل الإجراء التأديبي'),
                'penalty' => $penalty->fresh()->load(['employee.department', 'penaltyType']),
            ]);
        }

        return back()->with('toast', __('تم تسجيل الإجراء التأديبي'));
    }

    public function close(Penalty $penalty, PayrollPeriodGuard $periodGuard, Request $request, PayrollRunService $payrollRuns)
    {
        $periodGuard->ensureOpen($penalty->penalty_date);
        abort_if(in_array($penalty->status, Penalty::inactiveStatuses(), true), 422, __('الجزاء ملغى.'));
        $penalty->update([
            'status' => 'Approved',
            'closed_at' => now(),
        ]);
        $payrollRuns->refreshEmployeeInOpenDrafts((int) $penalty->employee_id);

        ActivityLog::record('penalty_close', "تم إغلاق الإجراء التأديبي للموظف ({$penalty->employee?->full_name}).", $penalty);

        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Penalty approved.', 'penalty' => $penalty->fresh()])
            : back()->with('toast', __('تم إغلاق الإجراء التأديبي بنجاح'));
    }

    public function update(Request $request, Penalty $penalty, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns)
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'penalty_type_id' => ['required', new \App\Rules\VisibleModel(\App\Models\PenaltyType::class)],
            'penalty_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
            'details' => ['nullable', 'string'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_days' => ['nullable', 'numeric', 'min:0'],
        ]);
        $periodGuard->ensureOpen($penalty->penalty_date);
        $periodGuard->ensureOpen($validated['penalty_date']);

        Employee::findOrFail($validated['employee_id']);
        $type = PenaltyType::findOrFail($validated['penalty_type_id']);
        $before = $penalty->toArray();
        $penalty->update([
            'employee_id' => $validated['employee_id'],
            'penalty_type_id' => $type->id,
            'penalty_date' => $validated['penalty_date'],
            'title' => $type->name,
            'reason' => $validated['reason'],
            'action' => $validated['details'] ?? $validated['reason'],
            'amount' => $validated['amount'] ?? 0,
            'deduction_days' => array_key_exists('deduction_days', $validated) ? $validated['deduction_days'] : $type->default_deduction_days,
            // التعديل يعيد الإجراء للمراجعة حتى لا يعتمد أثر مالي قديم على بيانات جديدة.
            'status' => 'Approved', 'closed_at' => now(),
        ]);
        $payrollRuns->refreshEmployeeInOpenDrafts((int) $penalty->employee_id);
        ActivityLog::record('penalty_update', "تم تعديل الإجراء التأديبي للموظف ({$penalty->employee?->full_name}).", $penalty, $before, $penalty->toArray(), 'discipline');
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Penalty updated.', 'penalty' => $penalty->fresh()->load(['employee.department', 'penaltyType'])])
            : back()->with('toast', __('تم تحديث الإجراء وإعادته للمراجعة.'));
    }

    public function destroy(Penalty $penalty, Request $request, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns)
    {
        $periodGuard->ensureOpen($penalty->penalty_date);
        $snapshot = $penalty->toArray();
        $penalty->delete();
        $payrollRuns->refreshEmployeeInOpenDrafts((int) $snapshot['employee_id']);
        ActivityLog::record('penalty_delete', 'تم حذف إجراء تأديبي.', null, $snapshot, null, 'discipline');
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Penalty deleted.', 'penalty_id' => $snapshot['id']])
            : back()->with('toast', __('تم حذف السجل'));
    }
}
