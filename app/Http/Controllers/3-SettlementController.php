<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Settlement;
use App\Services\SettlementService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettlementController extends Controller
{
    public function __construct(private readonly SettlementService $service) {}

    public function index(Request $request)
    {
        $settlements = Settlement::with('employee')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $q->whereHas('employee', fn ($e) => $e->where('full_name', 'like', '%'.$request->string('q').'%'));
            })
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return view('settlements.index', compact('settlements'));
    }

    public function create(Request $request)
    {
        $employee = null;
        if ($request->filled('employee_id')) {
            $employee = Employee::findOrFail((int) $request->integer('employee_id'));
            $active = Settlement::withoutGlobalScopes()
                ->where('employee_id', $employee->id)
                ->whereIn('status', Settlement::ACTIVE_STATUSES)
                ->latest('id')
                ->first();
            if ($active) {
                return redirect()->route('settlements.show', $active)->with('toast', 'توجد تسوية نشطة لهذا الموظف بالفعل.');
            }
        }

        $employees = $employee
            ? collect([$employee])
            : Employee::orderBy('full_name')->get(['id', 'full_name', 'employee_code']);

        return view('settlements.create', compact('employee', 'employees'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'reason' => ['required', Rule::in(Settlement::REASONS)],
            'last_working_day' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $employee = Employee::findOrFail((int) $data['employee_id']);
        $settlement = $this->service->start($employee, $data);

        return redirect()->route('settlements.show', $settlement)->with('toast', 'تم إنشاء مسودة التسوية. راجع القرارات المطلوبة قبل الاعتماد.');
    }

    public function show(Settlement $settlement)
    {
        $settlement->load(['employee', 'creator', 'approver', 'payer', 'payrollRun', 'payrollRecord']);
        $leavePreview = [
            'basic' => $this->service->leavePayoutFor($settlement, 'basic'),
            'gross' => $this->service->leavePayoutFor($settlement, 'gross'),
        ];

        return view('settlements.show', compact('settlement', 'leavePreview'));
    }

    public function update(Request $request, Settlement $settlement)
    {
        $data = $request->validate([
            'leave_payout_mode' => ['nullable', Rule::in(['pending', 'skip', 'basic', 'gross'])],
            'advance_recovery_mode' => ['nullable', Rule::in(['pending', 'scheduled', 'full'])],
            'gratuity_amount' => ['nullable', 'numeric', 'min:0'],
            'manual_earnings' => ['nullable', 'numeric', 'min:0'],
            'manual_deductions' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->service->updateDraft($settlement, $data);

        return back()->with('toast', 'تم حفظ قرارات التسوية.');
    }

    public function approve(Settlement $settlement)
    {
        $this->service->approve($settlement);

        $settlement->refresh();
        $message = $settlement->reconciliation === 'draft_run'
            ? 'تم اعتماد التسوية وإضافتها إلى مسير الرواتب المفتوح للمراجعة.'
            : 'تم اعتماد التسوية كصرف مستقل (لا يوجد مسير مفتوح للفترة).';

        return back()->with('toast', $message);
    }

    public function pay(Settlement $settlement)
    {
        $this->service->markPaid($settlement);

        return back()->with('toast', 'تم صرف التسوية وقفلها نهائيًا.');
    }

    public function cancel(Settlement $settlement)
    {
        $this->service->cancel($settlement);

        return redirect()->route('settlements.index')->with('toast', 'تم إلغاء مسودة التسوية.');
    }
}
