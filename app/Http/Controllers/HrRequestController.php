<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\HrRequest;
use App\Models\RequestType;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HrRequestController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = HrRequest::query()
            ->with(['employee.department', 'requestType', 'creator', 'reviewer'])
            ->visibleTo($user)
            ->latest('id');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('type')) {
            $type = RequestType::where('code', $request->string('type')->toString())->first();
            if ($type) {
                $query->where(function ($q) use ($type) {
                    $q->where('request_type_id', $type->id)->orWhere('request_type', $type->code);
                });
            }
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('details', 'like', "%{$search}%")
                    ->orWhereHas('employee', fn ($employee) => $employee
                        ->where('full_name', 'like', "%{$search}%")
                        ->orWhere('employee_code', 'like', "%{$search}%"));
            });
        }

        $perPage = in_array((int) $request->input('per_page', 25), [25, 50, 100, 200], true) ? (int) $request->input('per_page', 25) : 25;
        $requests = $query->paginate($perPage)->withQueryString();
        $selectedRequest = null;
        if ($request->filled('request')) {
            $selectedRequest = HrRequest::query()
                ->with(['employee.department', 'requestType', 'creator', 'reviewer'])
                ->visibleTo($user)
                ->find($request->integer('request'));
        }
        $requestTypes = RequestType::active()->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (RequestType $type) => $type->source_key !== 'leave');
        $employees = $this->targetEmployees($user);
        $employeeGrossSalaries = $employees->mapWithKeys(fn (Employee $employee) => [$employee->id => (float) ($employee->gross_salary ?? 0)]);

        $statsQuery = HrRequest::visibleTo($user);
        $stats = [
            'all' => (clone $statsQuery)->count(),
            'pending' => (clone $statsQuery)->where('status', 'Pending')->count(),
            'approved' => (clone $statsQuery)->where('status', 'Approved')->count(),
            'rejected' => (clone $statsQuery)->where('status', 'Rejected')->count(),
        ];
        $requestCardDetails = [
            'all' => (clone $statsQuery)->with('employee')->latest('id')->get(),
            'pending' => (clone $statsQuery)->with('employee')->where('status', 'Pending')->latest('id')->get(),
            'approved' => (clone $statsQuery)->with('employee')->where('status', 'Approved')->latest('id')->get(),
            'rejected' => (clone $statsQuery)->with('employee')->where('status', 'Rejected')->latest('id')->get(),
        ];

        return view('requests.index', compact('requests', 'requestTypes', 'employees', 'employeeGrossSalaries', 'stats', 'selectedRequest', 'requestCardDetails'));
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'request_type_id' => [
                'required',
                Rule::exists('request_types', 'id')->where(fn ($query) => $query->where('is_active', true)),
            ],
            'title' => ['required', 'string', 'max:200'],
            'details' => ['required', 'string', 'max:5000'],
            'request_date' => ['nullable', 'date'],
            'advance_request_date' => ['nullable', 'date'],
            'overtime_request_date' => ['nullable', 'date'],
            'other_request_date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'duration_days' => ['nullable', 'numeric', 'min:0'],
            'duration_hours' => ['nullable', 'numeric', 'min:0'],
            'day_overtime_hours' => ['nullable', 'numeric', 'min:0'],
            'night_overtime_hours' => ['nullable', 'numeric', 'min:0'],
            'overtime_period' => ['nullable', 'in:day,night'],
            'affects_payroll' => ['nullable', 'boolean'],
            'impact_type' => ['nullable', 'in:earning,deduction'],
            'impact_amount' => ['nullable', 'numeric', 'min:0'],
            'addition_amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'advance_amount' => ['nullable', 'numeric', 'gt:0'],
            'advance_over_limit_confirm' => ['nullable', 'boolean'],
        ]);

        $type = RequestType::active()->findOrFail($validated['request_type_id']);
        if ($type->source_key === 'overtime') {
            $overtimeValidated = $request->validate([
                'overtime_request_date' => ['required', 'date'],
                'overtime_period' => ['required', 'in:day,night'],
                'day_overtime_hours' => ['required_if:overtime_period,day', 'nullable', 'numeric', 'gt:0'],
                'night_overtime_hours' => ['required_if:overtime_period,night', 'nullable', 'numeric', 'gt:0'],
            ]);
            $validated = array_merge($validated, $overtimeValidated);
        }
        if ($type->source_key === 'other' && $request->boolean('affects_payroll')) {
            $otherValidated = $request->validate([
                'other_request_date' => ['required', 'date'],
                'impact_amount' => ['required', 'numeric', 'gt:0'],
                'impact_type' => ['required', 'in:earning,deduction'],
            ]);
            $validated = array_merge($validated, $otherValidated);
        }
        if ($type->source_key === 'other' && !$request->boolean('affects_payroll')) {
            $validated = array_merge($validated, $request->validate(['other_request_date' => ['required', 'date']]));
        }
        abort_if($type->source_key === 'leave', 422, 'طلبات الإجازة تُسجّل من وحدة الإجازات.');
        $employee = Employee::findOrFail($validated['employee_id']);
        abort_unless($this->canTargetEmployee($user, $employee), 403, 'لا يمكنك إنشاء طلب لهذا الموظف.');
        if ($type->source_key === 'advance') {
            $advanceValidated = $request->validate([
                'advance_request_date' => ['required', 'date'],
                'advance_amount' => ['required', 'numeric', 'gt:0'],
            ]);
            $validated = array_merge($validated, $advanceValidated);
            $limit = (float) ($employee->gross_salary ?? 0) * 0.10;
            if ((float) $validated['advance_amount'] > $limit) {
                $request->validate(['advance_over_limit_confirm' => ['accepted']]);
            }
        }

        $payload = $type->source_key === 'advance'
            ? [
                'request_date' => $validated['advance_request_date'],
                'amount' => (float) $validated['advance_amount'],
                'gross_salary' => (float) ($employee->gross_salary ?? 0),
                'limit_amount' => $limit,
                'exceeds_limit' => (float) $validated['advance_amount'] > $limit,
                'over_limit_confirmed' => $request->boolean('advance_over_limit_confirm'),
            ]
            : ($type->source_key === 'overtime'
            ? ['request_date' => $validated['overtime_request_date'], 'hours' => $validated[$validated['overtime_period'] === 'day' ? 'day_overtime_hours' : 'night_overtime_hours'], 'overtime_period' => $validated['overtime_period']]
            : ($type->source_key === 'other'
                ? collect(['request_date' => $validated['other_request_date'] ?? null, 'affects_payroll' => $request->boolean('affects_payroll'), 'impact_type' => $validated['impact_type'] ?? null, 'amount' => $validated['impact_amount'] ?? null])->filter(fn ($value) => $value !== null && $value !== '')->all()
            : collect([
            'request_date' => $validated['request_date'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'days' => $validated['duration_days'] ?? null,
            'hours' => $validated['duration_hours'] ?? null,
            'addition_amount' => $validated['addition_amount'] ?? null,
            'deduction_amount' => $validated['deduction_amount'] ?? null,
        ])->filter(fn ($value) => $value !== null && $value !== '')->all()));

        $hrRequest = HrRequest::create([
            'employee_id' => $employee->id,
            'request_type' => $type->code,
            'request_type_id' => $type->id,
            'title' => $validated['title'],
            'details' => $validated['details'] ?? null,
            'payload' => $payload ?: null,
            'status' => 'Pending',
            'created_by' => $user->id,
        ]);

        ActivityLog::record('request_create', "تم إنشاء طلب HR: {$hrRequest->title} للموظف {$employee->full_name}.", $hrRequest, null, $hrRequest->toArray(), 'requests');

        if ($request->wantsJson()) {
            $hrRequest->load(['employee', 'requestType']);
            return response()->json([
                'success' => true,
                'message' => 'HR request created.',
                'request' => [
                    'id' => $hrRequest->id,
                    'title' => $hrRequest->title,
                    'details' => $hrRequest->details,
                    'status' => $hrRequest->status,
                    'created_at' => $hrRequest->created_at?->toIso8601String(),
                    'request_type_label' => $hrRequest->requestType?->display_name ?? $hrRequest->request_type,
                    'employee' => [
                        'id' => $hrRequest->employee?->id,
                        'full_name' => $hrRequest->employee?->full_name,
                    ],
                ],
            ]);
        }

        return back()->with('toast', 'تم إنشاء الطلب وإرساله للمراجعة.');
    }

    public function approve(HrRequest $hrRequest, Request $request)
    {
        $user = $request->user();
        $this->authorizeVisible($hrRequest, $user);
        abort_unless($user->hasPermission('requests.approve'), 403, 'لا توجد صلاحية لاعتماد الطلبات.');
        abort_unless(in_array($hrRequest->status, ['Pending', 'Manager Approved'], true), 422, 'هذا الطلب ليس في حالة تسمح بالاعتماد.');

        $old = $hrRequest->only(['status', 'reviewed_by', 'reviewed_at', 'rejection_reason']);
        $hrRequest->update([
            'status' => 'Approved',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]);
        ActivityLog::record('request_approve', "تم اعتماد الطلب: {$hrRequest->title}.", $hrRequest, $old, $hrRequest->only(['status', 'reviewed_by', 'reviewed_at']), 'requests');

        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'HR request approved.', 'request' => $hrRequest->fresh()])
            : back()->with('toast', 'تم اعتماد الطلب.');
    }

    public function reject(HrRequest $hrRequest, Request $request)
    {
        $user = $request->user();
        $this->authorizeVisible($hrRequest, $user);
        abort_unless($user->hasPermission('requests.approve'), 403, 'لا توجد صلاحية لمراجعة الطلبات.');
        abort_unless(in_array($hrRequest->status, ['Pending', 'Manager Approved'], true), 422, 'هذا الطلب ليس في حالة تسمح بالرفض.');

        $validated = $request->validate([
            'rejection_reason' => ['nullable', 'string', 'max:5000'],
        ]);
        $old = $hrRequest->only(['status', 'reviewed_by', 'reviewed_at', 'rejection_reason']);
        $hrRequest->update([
            'status' => 'Rejected',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'rejection_reason' => $validated['rejection_reason'] ?? null,
        ]);
        ActivityLog::record('request_reject', "تم رفض الطلب: {$hrRequest->title}.", $hrRequest, $old, $hrRequest->only(['status', 'reviewed_by', 'reviewed_at', 'rejection_reason']), 'requests');

        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'HR request rejected.', 'request' => $hrRequest->fresh()])
            : back()->with('toast', 'تم رفض الطلب.');
    }

    public function destroy(HrRequest $hrRequest, Request $request)
    {
        $user = $request->user();
        $this->authorizeVisible($hrRequest, $user);
        abort_unless($user->hasPermission('requests.delete'), 403);
        abort_if($hrRequest->status !== 'Pending', 422, 'لا يمكن حذف طلب تمت مراجعته.');

        $snapshot = $hrRequest->toArray();
        $hrRequest->delete();
        ActivityLog::record('request_delete', "تم حذف الطلب: {$snapshot['title']}.", null, $snapshot, null, 'requests');

        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'HR request deleted.', 'request_id' => $snapshot['id']])
            : back()->with('toast', 'تم حذف الطلب.');
    }

    private function targetEmployees($user)
    {
        if ($user->role?->name === 'employee') {
            return Employee::whereKey($user->employee_id ?: 0)->get();
        }

        if ($user->role?->name === 'manager' && $user->employee_id) {
            return Employee::where(function ($q) use ($user) {
                $q->whereKey($user->employee_id)
                    ->orWhere('manager_employee_id', $user->employee_id);
            })->orderBy('full_name')->get();
        }

        return Employee::orderBy('full_name')->get();
    }

    private function canTargetEmployee($user, Employee $employee): bool
    {
        if ($user->isSuperAdmin() || !in_array($user->role?->name, ['employee', 'manager'], true)) {
            return true;
        }

        if ($user->role?->name === 'employee') {
            return (int) $user->employee_id === (int) $employee->id;
        }

        return (int) $employee->id === (int) $user->employee_id || (int) $employee->manager_employee_id === (int) $user->employee_id;
    }

    private function authorizeVisible(HrRequest $hrRequest, $user): void
    {
        abort_unless(
            HrRequest::query()->whereKey($hrRequest->id)->visibleTo($user)->exists(),
            403,
            'لا يمكنك الوصول إلى هذا الطلب.'
        );
    }
}
