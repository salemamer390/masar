<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\LeaveOpeningEntry;
use App\Services\LeaveBalanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The real initialization surface the setup alerts link to: the company's
 * carryover baseline date (visible, editable) plus explicit, dated, audited
 * opening entries per employee and cycle — including «confirmed zero», which
 * is a decision and stays distinguishable from unknown history.
 */
class LeaveOpeningBalanceController extends Controller
{
    public function index(LeaveBalanceService $balances)
    {
        $companyId = auth()->user()->company_id;
        $baseline = $balances->carryoverBaseline($companyId); // null until explicitly confirmed below
        $proposedBaseline = $baseline ?? today(); // proposal only — nothing persists on this read
        $employees = Employee::where('company_id', $companyId)
            ->whereNotNull('hire_date')->whereDate('hire_date', '<', $proposedBaseline)
            ->orderBy('full_name')->get();
        $entries = LeaveOpeningEntry::with('creator')->where('company_id', $companyId)->get()
            ->groupBy('employee_id');
        return view('leaves.opening', compact('baseline', 'proposedBaseline', 'employees', 'entries'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'employee_id' => ['required', 'integer'],
            'cycle_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'amount' => ['nullable', 'numeric', 'min:-999', 'max:999'],
            'is_confirmed_zero' => ['nullable', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        $employee = Employee::where('company_id', auth()->user()->company_id)->findOrFail($data['employee_id']);
        $confirmedZero = (bool) ($data['is_confirmed_zero'] ?? false);
        $amount = $confirmedZero ? 0.0 : (float) ($data['amount'] ?? 0);
        if (!$confirmedZero && $amount == 0.0) {
            return back()->withErrors(['amount' => 'أدخل رصيدًا موجبًا أو سالبًا، أو اختر «تأكيد عدم وجود رصيد».'])->withInput();
        }

        $entry = LeaveOpeningEntry::firstOrNew(['employee_id' => $employee->id, 'cycle_year' => $data['cycle_year']]);
        if (!$entry->exists) {
            $entry->company_id = $employee->company_id;
            $entry->created_by = auth()->id(); // audit: who recorded it, when = created_at
        }
        $entry->amount = $amount;
        $entry->is_confirmed_zero = $confirmedZero;
        $entry->reason = $data['reason'];
        $entry->save();

        return back()->with('toast', 'تم حفظ الرصيد الافتتاحي.');
    }

    public function updateBaseline(Request $request)
    {
        $data = $request->validate(['baseline' => ['required', 'date', 'before_or_equal:today']]);
        $companyId = auth()->user()->company_id;
        DB::table('leave_policy_settings')->updateOrInsert(
            ['company_id' => $companyId, 'setting_key' => 'leave_carryover_baseline'],
            ['setting_value' => $data['baseline'], 'label' => 'Leave carryover baseline date', 'updated_at' => now(), 'created_at' => now()]
        );
        return back()->with('toast', 'تم تحديث تاريخ بدء تتبع الترحيل.');
    }
}
