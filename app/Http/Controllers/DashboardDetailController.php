<?php

namespace App\Http\Controllers;

use App\Models\Leave;
use App\Models\PayrollRecord;
use App\Services\DashboardDataService;
use App\Services\UnifiedRequestService;
use Illuminate\Http\Request;

class DashboardDetailController extends Controller
{
    public function __construct(
        private readonly DashboardDataService $dashboard,
        private readonly UnifiedRequestService $requests,
    ) {}

    public function show(string $type, Request $request)
    {
        abort_unless(auth()->user()->hasPermission('dashboard.view'), 403);
        $allowed = ['employees', 'new-employees', 'attendance', 'present', 'absent', 'late', 'payroll-cost', 'payroll-net', 'payroll-summary', 'documents-expiring', 'documents-incomplete', 'requests', 'leaves'];
        abort_unless(in_array($type, $allowed, true), 404);
        $user = auth()->user();
        $search = trim((string) $request->query('search', ''));
        $items = collect();
        $title = match ($type) {
            'employees' => 'الموظفون النشطون', 'new-employees' => 'الموظفون الجدد', 'attendance' => 'حالة الحضور اليوم', 'present' => 'الحاضرون اليوم', 'absent' => 'الغائبون اليوم', 'late' => 'المتأخرون اليوم',
            'payroll-cost', 'payroll-summary' => 'ملخص الرواتب', 'payroll-net' => 'تفاصيل صافي الرواتب', 'documents-expiring' => 'المستندات التي تنتهي قريبًا',
            'documents-incomplete' => 'ملفات الموظفين غير المكتملة', 'requests' => 'آخر الطلبات', 'leaves' => 'آخر الإجازات', default => 'طلبات الإجازات المعلقة',
        };
        $sectionUrl = match ($type) {
            'employees', 'new-employees' => route('employees.index'),
            'attendance', 'present', 'absent', 'late' => route('attendance.index', ['date' => today()->toDateString()]),
            'payroll-cost', 'payroll-net', 'payroll-summary' => route('payroll.index', ['month' => (string) $request->query('payroll_month', today()->startOfMonth()->format('Y-m-01'))]),
            'documents-expiring' => route('documents.index'),
            'documents-incomplete' => route('employees.index'),
            'requests' => $this->requests->listingUrl($user), 'leaves' => route('leaves.index'),
            default => route('leaves.index', ['status' => 'Pending']),
        };

        if (in_array($type, ['employees', 'new-employees'], true)) {
            $newOnly = $type === 'new-employees';
            $items = $this->dashboard->activeEmployeesFor($user)->with(['department', 'job'])->when($search, fn ($q) => $q->where(fn ($x) => $x->where('full_name', 'like', "%{$search}%")->orWhere('employee_code', 'like', "%{$search}%")))->paginate(15)->withQueryString();
            if ($newOnly) $items = $this->dashboard->employeesFor($user)->with(['department', 'job'])->whereNotNull('hire_date')->whereDate('hire_date', '<=', today())->when($search, fn ($q) => $q->where(fn ($x) => $x->where('full_name', 'like', "%{$search}%")->orWhere('employee_code', 'like', "%{$search}%")))->latest('hire_date')->paginate(15)->withQueryString();
        } elseif (in_array($type, ['attendance', 'present', 'absent', 'late'], true)) {
            $states = $this->dashboard->attendance($user)['states']->filter(fn ($item) => $type === 'attendance' || $item['state'] === $type)->filter(fn ($item) => !$search || str_contains(mb_strtolower($item['employee']->full_name), mb_strtolower($search)))->values();
            $items = new \Illuminate\Pagination\LengthAwarePaginator($states->forPage((int) $request->query('page', 1), 15)->values(), $states->count(), 15, (int) $request->query('page', 1), ['path' => $request->url(), 'query' => $request->query()]);
        } elseif (in_array($type, ['payroll-cost', 'payroll-net', 'payroll-summary'], true)) {
            $monthInput = (string) $request->query('payroll_month', today()->format('Y-m'));
            try { $selectedMonth = \Carbon\Carbon::createFromFormat('Y-m', $monthInput)->startOfMonth(); } catch (\Throwable) { $selectedMonth = today()->startOfMonth(); }
            $payroll = $this->dashboard->payroll($user, $selectedMonth);
            $items = $payroll['run'] ? PayrollRecord::with('employee')->where('payroll_run_id', $payroll['run']->id)->whereIn('employee_id', $this->dashboard->employeesFor($user)->select('id'))->when($search, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('full_name', 'like', "%{$search}%")))->paginate(15)->withQueryString() : collect();
        } elseif ($type === 'documents-expiring') {
            $items = $this->dashboard->expiringDocuments($user)->filter(fn ($item) => !$search || str_contains(mb_strtolower(($item['employee']?->full_name ?? '').' '.($item['label'] ?? '')), mb_strtolower($search)))->values();
            $items = new \Illuminate\Pagination\LengthAwarePaginator($items->forPage((int) $request->query('page', 1), 15)->values(), $items->count(), 15, (int) $request->query('page', 1), ['path' => $request->url(), 'query' => $request->query()]);
        } elseif ($type === 'documents-incomplete') {
            $items = collect($this->dashboard->documentCompleteness($user)['employees'])->filter(fn ($item) => $item['complete'] < $item['required'])->filter(fn ($item) => !$search || str_contains(mb_strtolower($item['employee']->full_name), mb_strtolower($search)))->values();
            $items = new \Illuminate\Pagination\LengthAwarePaginator($items->forPage((int) $request->query('page', 1), 15)->values(), $items->count(), 15, (int) $request->query('page', 1), ['path' => $request->url(), 'query' => $request->query()]);
        } elseif ($type === 'requests') {
            $items = $this->requests->pending($user, null, $search);
        } else {
            $statuses = $type === 'leaves' ? ['Approved', 'Rejected'] : ['Pending', 'Manager Approved'];
            $items = Leave::with(['employee', 'leaveType'])->whereIn('status', $statuses)->when($search, fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('full_name', 'like', "%{$search}%")))->latest()->paginate(15)->withQueryString();
        }
        return view('dashboard.partials.details', compact('type', 'title', 'items', 'sectionUrl'));
    }

    public function employees(Request $request)
    {
        abort_unless(auth()->user()->hasPermission('documents.view'), 403);
        $term = trim((string) $request->query('search', ''));
        $items = $this->dashboard->employeesFor(auth()->user())->when($term, fn ($q) => $q->where(fn ($x) => $x->where('full_name', 'like', "%{$term}%")->orWhere('employee_code', 'like', "%{$term}%")))->orderBy('full_name')->limit(10)->get(['id', 'full_name', 'employee_code']);
        return response()->json($items->map(fn ($employee) => ['id' => $employee->id, 'name' => $employee->full_name, 'code' => $employee->employee_code, 'url' => route('employees.show', [$employee, 'tab' => 'documentsProfile'])]));
    }
}
