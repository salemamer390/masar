<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\HrRequest;
use App\Models\PayrollRecord;
use App\Models\RequestType;
use App\Services\PayrollRunService;
use App\Services\HrOperationalSettingsService;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class HrRequestController extends Controller
{
    public function index(Request $request, HrOperationalSettingsService $hrSettings)
    {
        $user = $request->user();
        $period = $hrSettings->payrollPeriod(today()->copy());
        $periodStart = $period['start']->toDateString();
        $periodEnd = $period['end']->toDateString();
        $query = HrRequest::query()
            ->with(['employee.department', 'requestType', 'creator', 'reviewer'])
            ->visibleTo($user)
            ->latest('id');

        // By default the list follows the company's configured payroll period.
        // Explicit filters remain available to review another period.
        $query->where(function (Builder $dateQuery) use ($periodStart, $periodEnd) {
            $dateQuery->whereBetween(
                \Illuminate\Support\Facades\DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.request_date'))"),
                [$periodStart, $periodEnd]
            )->orWhere(function (Builder $fallback) use ($periodStart, $periodEnd) {
                $fallback->whereNull(\Illuminate\Support\Facades\DB::raw("JSON_EXTRACT(payload, '$.request_date')"))
                    ->whereBetween('created_at', [$periodStart.' 00:00:00', $periodEnd.' 23:59:59']);
            });
        });

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
        $visibleRequestRows = HrRequest::visibleTo($user)->get(['employee_id', 'request_type', 'request_type_id', 'payload', 'status', 'created_at']);
        $employeeRequestMonths = $visibleRequestRows->filter(fn (HrRequest $row) => $row->status !== 'Rejected')
            ->groupBy('employee_id')
            ->map(fn ($rows) => $rows->map(function (HrRequest $row) {
                $date = data_get($row->payload, 'request_date') ?: $row->created_at?->toDateString();
                return $date ? Carbon::parse($date)->format('Y-m') : null;
            })->filter()->unique()->values()->all());
        $employeeOvertimeDays = $visibleRequestRows->filter(function (HrRequest $row) {
            return $row->status !== 'Rejected'
                && (($row->payload['overtime_period'] ?? null) !== null)
                && (($row->request_type ?? null) === 'overtime' || ($row->payload['overtime_period'] ?? null));
        })->groupBy('employee_id')->map(function ($rows) {
            return $rows->groupBy(function (HrRequest $row) {
                $date = data_get($row->payload, 'request_date') ?: $row->created_at?->toDateString();
                return $date ?: 'unknown';
            })->map(fn ($sameDay) => $sameDay->pluck('payload')->map(fn ($payload) => data_get($payload, 'overtime_period'))->filter()->unique()->values()->all())->all();
        })->all();
        $employeeAdvanceWarnings = [];
        $outstandingAdvances = $this->outstandingAdvancesFor($employees->pluck('id')->all());
        $outstandingAdvanceRequests = HrRequest::whereIn('id', collect($outstandingAdvances)->pluck('request_id')->filter()->all())->get()->keyBy('id');
        foreach ($employees as $employee) {
            $warning = $outstandingAdvances[$employee->id] ?? ['amount' => 0.0, 'request_id' => null];
            if ($warning['amount'] > 0) {
                $advance = $outstandingAdvanceRequests->get($warning['request_id']);
                $employeeAdvanceWarnings[$employee->id] = [
                    'amount' => $warning['amount'],
                    'request_id' => $warning['request_id'],
                    'date' => data_get($advance?->payload, 'request_date') ?: $advance?->created_at?->toDateString(),
                    'original_amount' => (float) data_get($advance?->payload, 'amount', 0),
                ];
            }
        }

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
        $editingRequest = null;
        if ($request->filled('edit')) {
            $editingRequest = HrRequest::query()->with(['employee', 'requestType'])->visibleTo($user)->find($request->integer('edit'));
        }
        $editingRequestData = $editingRequest ? [
            'id' => $editingRequest->id,
            'title' => $editingRequest->title,
            'details' => $editingRequest->details,
            'employee_id' => $editingRequest->employee_id,
            'employee_name' => $editingRequest->employee?->full_name,
            'request_type_id' => $editingRequest->request_type_id,
            'request_type_source' => $editingRequest->requestType?->source_key ?: $editingRequest->request_type,
            'payload' => $editingRequest->payload,
        ] : null;
        $requestValueData = $requests->getCollection()->mapWithKeys(function (HrRequest $hrRequest) {
            return [$hrRequest->id => $hrRequest->display_value];
        });

        return view('requests.index', compact('requests', 'requestTypes', 'employees', 'employeeGrossSalaries', 'employeeRequestMonths', 'employeeOvertimeDays', 'employeeAdvanceWarnings', 'stats', 'selectedRequest', 'editingRequest', 'editingRequestData', 'requestValueData', 'requestCardDetails'));
    }

    /** Return edit data without rendering the whole requests page. */
    public function editData(Request $request, HrRequest $hrRequest)
    {
        abort_unless(HrRequest::visibleTo($request->user())->whereKey($hrRequest->getKey())->exists(), 404);
        $hrRequest->load(['employee', 'requestType']);

        return response()->json([
            'id' => $hrRequest->id,
            'title' => $hrRequest->title,
            'details' => $hrRequest->details,
            'employee_id' => $hrRequest->employee_id,
            'employee_name' => $hrRequest->employee?->full_name,
            'request_type_id' => $hrRequest->request_type_id,
            'request_type_source' => $hrRequest->requestType?->source_key ?: $hrRequest->request_type,
            'payload' => $hrRequest->payload,
        ]);
    }

    public function store(Request $request, PayrollRunService $payrollRuns)
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
            'financial_request_date' => ['nullable', 'date'],
            'financial_amount' => ['nullable', 'numeric', 'gt:0'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'duration_days' => ['nullable', 'numeric', 'min:0'],
            'duration_hours' => ['nullable', 'numeric', 'min:0'],
            'day_overtime_hours' => ['nullable', 'numeric', 'min:0'],
            'night_overtime_hours' => ['nullable', 'numeric', 'min:0'],
            'overtime_period' => ['nullable', 'in:day,night'],
            'from_time' => ['nullable', 'date_format:H:i'],
            'to_time' => ['nullable', 'date_format:H:i'],
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
            // Accept the legacy field name as well. Older cached pages used
            // request_date even though overtime validation uses the explicit
            // overtime_request_date key.
            if (!$request->filled('overtime_request_date') && $request->filled('request_date')) {
                $request->merge(['overtime_request_date' => $request->input('request_date')]);
            }
            $overtimeValidated = $request->validate([
                'overtime_request_date' => ['required', 'date'],
                'overtime_period' => ['required', 'in:day,night'],
                'day_overtime_hours' => ['required_if:overtime_period,day', 'nullable', 'numeric', 'gt:0'],
                'night_overtime_hours' => ['required_if:overtime_period,night', 'nullable', 'numeric', 'gt:0'],
                'from_time' => ['required', 'date_format:H:i'],
                'to_time' => ['required', 'date_format:H:i', 'different:from_time'],
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
        if (in_array($type->source_key, ['bonus', 'incentive', 'commission', 'profit_share'], true)) {
            $financialValidated = $request->validate([
                'financial_request_date' => ['required', 'date'],
                'financial_amount' => ['required', 'numeric', 'gt:0'],
            ]);
            $validated = array_merge($validated, $financialValidated);
        }
        abort_if($type->source_key === 'leave', 422, 'طلبات الإجازة تُسجّل من وحدة الإجازات.');
        $employee = Employee::findOrFail($validated['employee_id']);
        abort_unless($this->canTargetEmployee($user, $employee), 403, 'لا يمكنك إنشاء طلب لهذا الموظف.');
        $requestDate = match ($type->source_key) {
            'advance' => $validated['advance_request_date'] ?? null,
            'overtime' => $validated['overtime_request_date'] ?? null,
            'other' => $validated['other_request_date'] ?? null,
            'bonus', 'incentive', 'commission', 'profit_share' => $validated['financial_request_date'] ?? null,
            default => $validated['request_date'] ?? null,
        };
        if ($requestDate) {
            $requestMonth = Carbon::parse($requestDate)->startOfMonth();
            $duplicateQuery = HrRequest::where('employee_id', $employee->id)
                ->where('status', '!=', 'Rejected')
                // Requests of different types may coexist in the same
                // month (for example, an advance and overtime). Prevent
                // only a duplicate of the selected request type.
                ->where(function ($query) use ($type) {
                    $query->where('request_type_id', $type->id)
                        ->orWhere('request_type', $type->code);
                });
            $duplicate = $duplicateQuery->get(['payload', 'created_at'])
                ->contains(function (HrRequest $existing) use ($requestMonth, $type, $requestDate, $validated): bool {
                    $existingDate = data_get($existing->payload, 'request_date') ?: $existing->created_at?->toDateString();
                    if (!$existingDate) return false;
                    if ($type->source_key === 'overtime') {
                        return Carbon::parse($existingDate)->isSameDay(Carbon::parse($requestDate))
                            && data_get($existing->payload, 'overtime_period') === ($validated['overtime_period'] ?? null);
                    }
                    return Carbon::parse($existingDate)->isSameMonth($requestMonth);
                });
            if ($duplicate) {
                $errorKey = $type->source_key === 'overtime'
                    ? 'overtime_request_date'
                    : (in_array($type->source_key, ['bonus', 'incentive', 'commission', 'profit_share'], true) ? 'financial_request_date' : 'request_date');
                $message = $type->source_key === 'overtime'
                    ? 'يوجد إضافي من نفس النوع لهذا الموظف في نفس اليوم. يمكن إضافة النوع الآخر في اليوم نفسه.'
                    : 'يوجد طلب قائم لهذا الموظف داخل نفس الشهر. لا يمكن إنشاء طلب آخر من النوع نفسه.';
                throw ValidationException::withMessages([$errorKey => $message]);
            }
        }
        if ($type->source_key === 'advance') {
            $advanceValidated = $request->validate([
                'advance_request_date' => ['required', 'date'],
                'advance_amount' => ['required', 'numeric', 'gt:0'],
            ]);
            $validated = array_merge($validated, $advanceValidated);
            $limit = (float) ($employee->gross_salary ?? 0) * app(\App\Services\PayrollSettingsService::class)->advanceMaxFraction();
            if ((float) $validated['advance_amount'] > $limit) {
                $request->validate(['advance_over_limit_confirm' => ['accepted']]);
            }
            $outstandingAdvance = $this->outstandingAdvanceFor($employee->id);
            if ($outstandingAdvance['amount'] > 0) {
                if (!$request->boolean('advance_existing_confirm')) {
                    throw ValidationException::withMessages([
                        'advance_existing_confirm' => 'لدى الموظف سلفة سابقة غير مسددة. فعّل مربع التأكيد للمتابعة أو عدّل السلفة السابقة أولاً.',
                    ]);
                }
            }
        }

        if ($type->source_key === 'advance') {
            $payload = [
                'request_date' => $validated['advance_request_date'],
                'amount' => (float) $validated['advance_amount'],
                'gross_salary' => (float) ($employee->gross_salary ?? 0),
                'limit_amount' => $limit,
                'exceeds_limit' => (float) $validated['advance_amount'] > $limit,
                'over_limit_confirmed' => $request->boolean('advance_over_limit_confirm'),
                'previous_outstanding_amount' => $outstandingAdvance['amount'],
                'previous_outstanding_request_id' => $outstandingAdvance['request_id'],
                'previous_outstanding_confirmed' => $request->boolean('advance_existing_confirm'),
            ];
        } elseif ($type->source_key === 'overtime') {
            $payload = [
                'request_date' => $validated['overtime_request_date'],
                'hours' => $validated[$validated['overtime_period'] === 'day' ? 'day_overtime_hours' : 'night_overtime_hours'],
                'overtime_period' => $validated['overtime_period'],
                'from_time' => $validated['from_time'],
                'to_time' => $validated['to_time'],
            ];
        } elseif (in_array($type->source_key, ['bonus', 'incentive', 'commission', 'profit_share'], true)) {
            $payload = [
                'request_date' => $validated['financial_request_date'],
                'amount' => (float) $validated['financial_amount'],
                'affects_payroll' => true,
                'impact_type' => 'earning',
            ];
        } elseif ($type->source_key === 'other') {
            $payload = collect([
                'request_date' => $validated['other_request_date'] ?? null,
                'affects_payroll' => $request->boolean('affects_payroll'),
                'impact_type' => $validated['impact_type'] ?? null,
                'amount' => $validated['impact_amount'] ?? null,
            ])->filter(fn ($value) => $value !== null && $value !== '')->all();
        } else {
            $payload = collect([
                'request_date' => $validated['request_date'] ?? null,
                'start_date' => $validated['start_date'] ?? null,
                'end_date' => $validated['end_date'] ?? null,
                'days' => $validated['duration_days'] ?? null,
                'hours' => $validated['duration_hours'] ?? null,
                'addition_amount' => $validated['addition_amount'] ?? null,
                'deduction_amount' => $validated['deduction_amount'] ?? null,
            ])->filter(fn ($value) => $value !== null && $value !== '')->all();
        }

        $hrRequest = HrRequest::create([
            'employee_id' => $employee->id,
            'request_type' => $type->code,
            'request_type_id' => $type->id,
            'title' => $validated['title'],
            'details' => $validated['details'] ?? null,
            'payload' => $payload ?: null,
            'status' => 'Approved',
            'created_by' => $user->id,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        // Requests are effective immediately; refresh only this employee in
        // open payroll drafts instead of rebuilding the whole company run.
        $payrollRuns->refreshEmployeeInOpenDrafts($employee->id);

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
                    'request_date' => data_get($hrRequest->payload, 'request_date') ?: $hrRequest->created_at?->toDateString(),
                    'request_type_label' => $hrRequest->requestType?->display_name ?? $hrRequest->request_type,
                    'value' => $hrRequest->display_value,
                    'employee' => [
                        'id' => $hrRequest->employee?->id,
                        'full_name' => $hrRequest->employee?->full_name,
                    ],
                ],
            ]);
        }

        return back()->with('toast', 'تم إنشاء الطلب وإرساله للمراجعة.');
    }

    public function update(Request $request, HrRequest $hrRequest, PayrollRunService $payrollRuns)
    {
        $user = $request->user();
        $this->authorizeVisible($hrRequest, $user);
        abort_unless($user->hasPermission('requests.create'), 403, 'لا توجد صلاحية لتعديل الطلبات.');
        $hrRequest->loadMissing(['requestType', 'employee']);
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'details' => ['nullable', 'string', 'max:5000'],
        ]);
        $source = $hrRequest->requestType?->source_key ?: $hrRequest->request_type;
        $payload = (array) ($hrRequest->payload ?? []);
        if ($source === 'advance') {
            $data = $request->validate(['advance_request_date' => ['required', 'date'], 'advance_amount' => ['required', 'numeric', 'gt:0']]);
            $gross = (float) ($hrRequest->employee?->gross_salary ?? data_get($payload, 'gross_salary', 0));
            $payload = array_merge($payload, ['request_date' => $data['advance_request_date'], 'amount' => (float) $data['advance_amount'], 'gross_salary' => $gross, 'limit_amount' => $gross * app(\App\Services\PayrollSettingsService::class)->advanceMaxFraction(), 'exceeds_limit' => (float) $data['advance_amount'] > ($gross * 0.10)]);
        } elseif ($source === 'overtime') {
            if (!$request->filled('overtime_request_date') && $request->filled('request_date')) {
                $request->merge(['overtime_request_date' => $request->input('request_date')]);
            }
            $data = $request->validate(['overtime_request_date' => ['required', 'date'], 'overtime_period' => ['required', 'in:day,night'], 'day_overtime_hours' => ['required_if:overtime_period,day', 'nullable', 'numeric', 'gt:0'], 'night_overtime_hours' => ['required_if:overtime_period,night', 'nullable', 'numeric', 'gt:0'], 'from_time' => ['required', 'date_format:H:i'], 'to_time' => ['required', 'date_format:H:i', 'different:from_time']]);
            $duplicate = HrRequest::where('id', '!=', $hrRequest->id)
                ->where('employee_id', $hrRequest->employee_id)
                ->where('status', '!=', 'Rejected')
                ->where(function ($query) use ($hrRequest) {
                    $query->where('request_type_id', $hrRequest->request_type_id)
                        ->orWhere('request_type', $hrRequest->request_type);
                })->get(['payload', 'created_at'])
                ->contains(function (HrRequest $existing) use ($data): bool {
                    $existingDate = data_get($existing->payload, 'request_date') ?: $existing->created_at?->toDateString();
                    return $existingDate
                        && Carbon::parse($existingDate)->isSameDay(Carbon::parse($data['overtime_request_date']))
                        && data_get($existing->payload, 'overtime_period') === $data['overtime_period'];
                });
            if ($duplicate) {
                throw ValidationException::withMessages(['overtime_request_date' => 'يوجد إضافي من نفس النوع لهذا الموظف في نفس اليوم.']);
            }
            $hours = $data['overtime_period'] === 'day' ? $data['day_overtime_hours'] : $data['night_overtime_hours'];
            $payload = array_merge($payload, ['request_date' => $data['overtime_request_date'], 'hours' => (float) $hours, 'overtime_period' => $data['overtime_period'], 'from_time' => $data['from_time'], 'to_time' => $data['to_time']]);
        } elseif ($source === 'other') {
            $data = $request->validate(['other_request_date' => ['required', 'date'], 'affects_payroll' => ['nullable', 'boolean'], 'impact_type' => ['required_if:affects_payroll,1', 'nullable', 'in:earning,deduction'], 'impact_amount' => ['required_if:affects_payroll,1', 'nullable', 'numeric', 'gt:0']]);
            $payload = array_merge($payload, ['request_date' => $data['other_request_date'], 'affects_payroll' => $request->boolean('affects_payroll'), 'impact_type' => $data['impact_type'] ?? null, 'amount' => $data['impact_amount'] ?? null]);
        } elseif (in_array($source, ['bonus', 'incentive', 'commission', 'profit_share'], true)) {
            $data = $request->validate([
                'financial_request_date' => ['required', 'date'],
                'financial_amount' => ['required', 'numeric', 'gt:0'],
            ]);
            $payload = array_merge($payload, [
                'request_date' => $data['financial_request_date'],
                'amount' => (float) $data['financial_amount'],
                'affects_payroll' => true,
                'impact_type' => 'earning',
            ]);
        } else {
            $data = $request->validate(['request_date' => ['nullable', 'date'], 'start_date' => ['nullable', 'date'], 'end_date' => ['nullable', 'date', 'after_or_equal:start_date'], 'duration_days' => ['nullable', 'numeric', 'min:0'], 'duration_hours' => ['nullable', 'numeric', 'min:0'], 'addition_amount' => ['nullable', 'numeric', 'min:0'], 'deduction_amount' => ['nullable', 'numeric', 'min:0']]);
            $payload = collect(array_merge($payload, ['request_date' => $data['request_date'] ?? null, 'start_date' => $data['start_date'] ?? null, 'end_date' => $data['end_date'] ?? null, 'days' => $data['duration_days'] ?? null, 'hours' => $data['duration_hours'] ?? null, 'addition_amount' => $data['addition_amount'] ?? null, 'deduction_amount' => $data['deduction_amount'] ?? null]))->filter(fn ($value) => $value !== null && $value !== '')->all();
        }
        $old = $hrRequest->only(['title', 'details', 'payload', 'status']);
        $hrRequest->update(array_merge($validated, ['payload' => $payload ?: null,
            'status' => 'Approved',
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'rejection_reason' => null,
        ]));
        $payrollRuns->refreshEmployeeInOpenDrafts((int) $hrRequest->employee_id);
        ActivityLog::record('request_update', "تم تعديل طلب HR: {$hrRequest->title}.", $hrRequest, $old, $hrRequest->fresh()->only(['title', 'details', 'status']), 'requests');
        $updated = $hrRequest->fresh(['employee', 'requestType']);
        $updated->setAttribute('value', $updated->display_value);
        $updated->append('display_value');
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'تم تعديل الطلب.',
                'request' => [
                    'id' => $updated->id,
                    'title' => $updated->title,
                    'details' => $updated->details,
                    'status' => $updated->status,
                    'created_at' => $updated->created_at?->toIso8601String(),
                    'request_date' => data_get($updated->payload, 'request_date') ?: $updated->created_at?->toDateString(),
                    'request_type_label' => $updated->requestType?->display_name ?? $updated->request_type,
                    'value' => $updated->display_value,
                    'display_value' => $updated->display_value,
                ],
            ]);
        }
        return $request->wantsJson()
            ? response()->json(['success' => true, 'message' => 'تم تعديل الطلب.', 'request' => $hrRequest->fresh(['employee', 'requestType'])])
            : redirect()->route('requests.index')->with('toast', 'تم تعديل الطلب بنجاح.');
    }

    private function outstandingAdvanceFor(int $employeeId): array
    {
        return $this->outstandingAdvancesFor([$employeeId])[$employeeId] ?? ['amount' => 0.0, 'request_id' => null];
    }

    private function outstandingAdvancesFor(array $employeeIds): array
    {
        $advances = HrRequest::with('requestType')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'Approved')
            ->where(function ($query) {
                $query->where('request_type', 'advance')
                    ->orWhereHas('requestType', fn ($type) => $type->where('source_key', 'advance'));
            })->orderBy('id')->get();
        $paidByRequest = [];
        PayrollRecord::whereIn('employee_id', $employeeIds)
            ->get(['calculation_snapshot'])
            ->each(function (PayrollRecord $record) use (&$paidByRequest): void {
                foreach ((array) data_get($record->calculation_snapshot, 'advance_details', []) as $detail) {
                    $id = (int) ($detail['request_id'] ?? 0);
                    if ($id > 0) $paidByRequest[$id] = ($paidByRequest[$id] ?? 0) + (float) ($detail['installment'] ?? 0);
                }
            });
        $result = [];
        foreach ($advances as $advance) {
            if (isset($result[$advance->employee_id]) && $result[$advance->employee_id]['amount'] > 0) continue;
            $amount = (float) data_get($advance->payload, 'amount', 0);
            $remaining = round(max(0, $amount - (float) ($paidByRequest[$advance->id] ?? 0)), 2);
            if ($remaining > 0) $result[$advance->employee_id] = ['amount' => $remaining, 'request_id' => $advance->id];
        }
        return $result;
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

    public function destroy(HrRequest $hrRequest, Request $request, PayrollRunService $payrollRuns)
    {
        $user = $request->user();
        $this->authorizeVisible($hrRequest, $user);
        abort_unless($user->hasPermission('requests.delete'), 403);
        $snapshot = $hrRequest->toArray();
        $deletedEmployeeId = (int) $hrRequest->employee_id;
        $deletedRequestDate = data_get($hrRequest->payload, 'request_date') ?: $hrRequest->created_at?->toDateString();
        $hrRequest->delete();
        $payrollRuns->refreshEmployeeInOpenDrafts($deletedEmployeeId);
        ActivityLog::record('request_delete', "تم حذف الطلب: {$snapshot['title']}.", null, $snapshot, null, 'requests');

        $remainingAdvance = $this->outstandingAdvanceFor($deletedEmployeeId);
        if (($remainingAdvance['amount'] ?? 0) > 0 && !empty($remainingAdvance['request_id'])) {
            $remainingRequest = HrRequest::find($remainingAdvance['request_id']);
            $remainingAdvance['date'] = data_get($remainingRequest?->payload, 'request_date') ?: $remainingRequest?->created_at?->toDateString();
            $remainingAdvance['original_amount'] = (float) data_get($remainingRequest?->payload, 'amount', $remainingAdvance['amount']);
        }

        return $request->wantsJson()
            ? response()->json([
                'success' => true,
                'message' => 'تم حذف الطلب بنجاح.',
                'request_id' => $snapshot['id'],
                'employee_id' => $deletedEmployeeId,
                'request_date' => $deletedRequestDate,
                'remaining_advance' => $remainingAdvance,
            ])
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
