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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

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
        // Policy penalties (lateness / early departure) are computed from attendance, not stored, so
        // they are listed read-only for the same filters and period.
        $listFrom = \Carbon\Carbon::parse($request->filled('from_date') ? (string) $request->from_date : $periodStart)->startOfDay();
        $listTo = \Carbon\Carbon::parse($request->filled('to_date') ? (string) $request->to_date : $periodEnd)->startOfDay();
        $attendancePenalties = ['enabled' => false, 'rows' => []];
        if ($listFrom->lte($listTo) && $listFrom->diffInDays($listTo) <= 93) {
            $listed = Employee::query()
                ->when($request->filled('employee_id'), fn ($q) => $q->where('id', $request->employee_id))
                ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->department_id))
                ->limit(500)->get();
            $attendancePenalties = app(\App\Services\AttendancePenaltyListing::class)->forPeriod($listed, $listFrom, $listTo);
        }
        return view('discipline.index', compact('penalties', 'employees', 'penaltyTypes', 'departments', 'periodStart', 'periodEnd', 'attendancePenalties'));
    }

    public function store(Request $request, PayrollPeriodGuard $periodGuard, PayrollRunService $payrollRuns)
    {
        $validated = $this->validatePenalty($request);
        $periodGuard->ensureOpen($validated['penalty_date']);
        $employee = Employee::findOrFail($validated['employee_id']);

        $penalty = DB::transaction(function () use ($validated, $request, $employee, $payrollRuns): Penalty {
            $type = $this->resolvePenaltyType($validated);
            $penalty = Penalty::create([
                'employee_id' => $employee->id,
                'penalty_type_id' => $type->id,
                'penalty_date' => $validated['penalty_date'],
                'title' => $type->name,
                'reason' => $validated['reason'],
                'action' => $validated['reason'],
                'amount' => $request->has('deduct_from_salary') ? 0 : ($validated['amount'] ?? 0),
                'deduction_days' => $this->deductionDays($request, $validated, $type),
                // Entries are immediately effective, with no approval queue.
                'status' => 'Approved',
                'issued_by' => auth()->id(),
            ]);
            $payrollRuns->refreshEmployeeInOpenDrafts((int) $employee->id);
            ActivityLog::record('penalty_create', "تم تسجيل إجراء تأديبي ({$type->name}) بحق الموظف ({$employee->full_name}).", $penalty);
            return $penalty;
        });

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
        $validated = $this->validatePenalty($request);
        $periodGuard->ensureOpen($penalty->penalty_date);
        $periodGuard->ensureOpen($validated['penalty_date']);
        $employee = Employee::findOrFail($validated['employee_id']);

        DB::transaction(function () use ($request, $validated, $penalty, $employee, $payrollRuns): void {
            $type = $this->resolvePenaltyType($validated);
            $before = $penalty->toArray();
            $oldEmployeeId = (int) $penalty->employee_id;
            $penalty->update([
                'employee_id' => $employee->id,
                'penalty_type_id' => $type->id,
                'penalty_date' => $validated['penalty_date'],
                'title' => $type->name,
                'reason' => $validated['reason'],
                // Historical details and legacy monetary metadata stay intact.
                // Payroll uses deduction_days, not this legacy amount column.
                'amount' => $request->has('deduct_from_salary') ? $penalty->amount : ($validated['amount'] ?? $penalty->amount),
                'deduction_days' => $this->deductionDays($request, $validated, $type),
                'status' => 'Approved', 'closed_at' => now(),
            ]);
            $payrollRuns->refreshEmployeeInOpenDrafts($oldEmployeeId);
            if ($oldEmployeeId !== (int) $employee->id) {
                $payrollRuns->refreshEmployeeInOpenDrafts((int) $employee->id);
            }
            ActivityLog::record('penalty_update', "تم تعديل الإجراء التأديبي للموظف ({$employee->full_name}).", $penalty, $before, $penalty->toArray(), 'discipline');
        });
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'Penalty updated.', 'penalty' => $penalty->fresh()->load(['employee.department', 'penaltyType'])])
            : back()->with('toast', __('تم تحديث الإجراء التأديبي.'));
    }

    private function validatePenalty(Request $request): array
    {
        if (is_string($request->input('penalty_type_name'))) {
            $request->merge(['penalty_type_name' => preg_replace('/\s+/u', ' ', trim((string) $request->input('penalty_type_name')))]);
        }
        return $request->validate([
            'employee_id' => ['required', 'integer', new \App\Rules\VisibleModel(Employee::class)],
            'penalty_type_id' => ['nullable', 'integer', new \App\Rules\VisibleModel(PenaltyType::class)],
            'penalty_type_name' => ['required_without:penalty_type_id', 'nullable', 'string', 'max:100'],
            'penalty_date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:500'],
            'deduct_from_salary' => ['sometimes', 'boolean'],
            'deduction_days' => [Rule::requiredIf($request->boolean('deduct_from_salary')), 'nullable', 'numeric', 'max:999.99', $request->boolean('deduct_from_salary') ? 'min:0.01' : 'min:0', $request->boolean('deduct_from_salary') ? 'decimal:0,2' : 'numeric'],
            'amount' => ['nullable', 'numeric', 'min:0'],
        ], [
            'penalty_type_name.required_without' => __('اكتب نوع الجزاء أو اختره من القائمة.'),
            'deduction_days.required' => __('حدد عدد أيام الخصم.'),
            'deduction_days.min' => __('عدد أيام الخصم يجب ألا يقل عن 0.01 يوم.'),
            'deduction_days.decimal' => __('عدد أيام الخصم يقبل منزلتين عشريتين كحد أقصى.'),
        ]);
    }

    private function resolvePenaltyType(array $validated): PenaltyType
    {
        if (!empty($validated['penalty_type_id'])) {
            return PenaltyType::findOrFail($validated['penalty_type_id']);
        }
        // Exact name matching only. LIKE could accidentally select a different
        // sanction, and new types must never be created in another tenant.
        $name = $validated['penalty_type_name'];
        $companyId = auth()->user()->company_id;
        if ($companyId) {
            \App\Models\Company::whereKey($companyId)->lockForUpdate()->first();
        }
        $type = PenaltyType::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->orderByRaw('company_id IS NULL')->first();
        if ($type) return $type;
        $type = PenaltyType::create([
            'name' => $name, 'company_id' => $companyId,
            'is_active' => true, 'default_deduction_days' => 0,
        ]);
        ActivityLog::record('master_create', "تمت إضافة نوع جزاء ({$name}) من نموذج تسجيل الجزاء.", $type);
        return $type;
    }

    private function deductionDays(Request $request, array $validated, PenaltyType $type): float
    {
        if ($request->has('deduct_from_salary')) {
            return $request->boolean('deduct_from_salary') ? (float) $validated['deduction_days'] : 0.0;
        }
        // Preserve the existing API/import contract for older clients. The
        // web forms always submit an explicit checkbox value, including off.
        return (float) ($validated['deduction_days'] ?? $type->default_deduction_days ?? 0);
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
