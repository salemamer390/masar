<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Leave;
use App\Models\MovementRecord;
use App\Services\DashboardDataService;
use App\Services\UnifiedRequestService;

class DashboardController extends Controller
{
    public function __construct(
        private readonly DashboardDataService $dashboard,
        private readonly UnifiedRequestService $requests,
    ) {}

    public function index(\Illuminate\Http\Request $request)
    {
        $user = auth()->user();
        abort_if($user?->role?->name === 'employee', 403, 'لوحة التحكم الإدارية غير متاحة لحساب الموظف.');
        $can = fn (string $permission) => (bool) $user?->hasPermission($permission);
        $today = today();
        $currentMonth = $today->copy()->startOfMonth();
        $monthInput = (string) $request->input('payroll_month', '');
        if ($monthInput === '' && $request->filled('payroll_month_number') && $request->filled('payroll_year')) {
            $monthInput = sprintf('%04d-%02d', (int) $request->input('payroll_year'), (int) $request->input('payroll_month_number'));
        }
        if ($monthInput === '') $monthInput = $today->format('Y-m');
        try { $month = \Carbon\Carbon::createFromFormat('Y-m', $monthInput)->startOfMonth(); } catch (\Throwable) { $month = $today->copy()->startOfMonth(); }
        $attendance = $can('attendance.view') ? $this->dashboard->attendance($user, $today) : null;
        $active = $can('employees.view') ? $this->dashboard->activeEmployeesFor($user)->count() : null;
        $newHires = $can('employees.view') ? $this->dashboard->employeesFor($user)->whereBetween('hire_date', [$currentMonth, $currentMonth->copy()->endOfMonth()])->whereDate('hire_date', '<=', $today)->count() : 0;
        $payroll = $can('payroll.view') ? $this->dashboard->payroll($user, $month) : null;
        $documents = $can('documents.view') ? $this->dashboard->documentCompleteness($user) : null;
        $expiringDocuments = $can('documents.view') ? $this->dashboard->expiringDocuments($user) : collect();
        $allPendingRequests = $this->requests->pending($user, null);
        $pendingRequests = $allPendingRequests->take(5)->values();
        $pendingRequestsCount = $allPendingRequests->count();
        $requestsIndexUrl = $this->requests->listingUrl($user);
        $recentLeaves = $can('leaves.view') ? Leave::with(['employee', 'leaveType'])->whereIn('status', ['Approved', 'Rejected'])->latest('approved_at')->latest('updated_at')->limit(7)->get() : collect();
        $recentEmployees = collect();
        if ($can('employees.view')) {
            $recentEmployees = $this->dashboard->employeesFor($user)->with(['job', 'status'])->whereNotNull('hire_date')->whereBetween('hire_date', [$currentMonth, $currentMonth->copy()->endOfMonth()])->whereDate('hire_date', '<=', $today)->latest('hire_date')->limit(7)->get();
            if ($recentEmployees->isEmpty()) $recentEmployees = $this->dashboard->employeesFor($user)->with(['job', 'status'])->whereNotNull('hire_date')->whereDate('hire_date', '<=', $today)->latest('hire_date')->limit(7)->get();
        }
        $recentMovements = $can('movements.view') ? MovementRecord::with(['employee', 'movementType'])->latest('movement_date')->limit(5)->get() : collect();

        $heroMetrics = [];
        if ($can('employees.view')) $heroMetrics[] = ['key' => 'employees', 'label' => 'الموظفون النشطون', 'value' => format_number($active), 'hint' => $newHires ? '+'.$newHires.' هذا الشهر' : 'الموظفون النشطون', 'icon' => 'people-fill', 'tone' => 'blue', 'url' => route('employees.index')];
        if ($attendance) {
            $heroMetrics[] = ['key' => 'present', 'label' => 'الحاضرون اليوم', 'value' => format_number($attendance['counts']['present']), 'hint' => 'من أصل '.format_number($attendance['counts']['scheduled']).' مجدولين', 'icon' => 'person-check-fill', 'tone' => 'green', 'url' => route('attendance.index', ['date' => $today->toDateString()])];
            $heroMetrics[] = ['key' => 'absent', 'label' => 'الغائبون اليوم', 'value' => format_number($attendance['counts']['absent']), 'hint' => $attendance['cutoffReached'] ? 'بعد وقت احتساب الغياب' : 'لم يحن وقت الاحتساب', 'icon' => 'person-x-fill', 'tone' => 'red', 'url' => route('attendance.index', ['date' => $today->toDateString()])];
            $heroMetrics[] = ['key' => 'late', 'label' => 'المتأخرون اليوم', 'value' => format_number($attendance['counts']['late']), 'hint' => 'جزء من الحاضرين', 'icon' => 'clock-history', 'tone' => 'orange', 'url' => route('attendance.index', ['date' => $today->toDateString()])];
        }
        if ($this->requests->canViewAny($user)) $heroMetrics[] = ['key' => 'requests', 'label' => 'طلبات معلقة', 'value' => format_number($pendingRequestsCount), 'hint' => 'تحتاج إلى اعتماد', 'icon' => 'calendar2-check-fill', 'tone' => 'purple', 'url' => $requestsIndexUrl];
        if ($payroll) {
            $heroMetrics[] = ['key' => 'payroll-cost', 'label' => 'إجمالي تكلفة الموظفين', 'value' => $payroll['run'] ? format_money($payroll['cost']).' ج.م' : '—', 'hint' => $payroll['run'] ? 'دورة '.$month->format('m/Y') : 'لا توجد دورة لهذا الشهر', 'icon' => 'cash-stack', 'tone' => 'money', 'url' => route('payroll.index', ['month' => $month->format('Y-m-01')])];
        }

        $attentionItems = [];
        if ($pendingRequests->isNotEmpty()) $attentionItems[] = ['key' => 'requests', 'title' => 'طلبات بانتظار القرار', 'count' => $pendingRequestsCount, 'description' => 'طلبات تحتاج مراجعة أو اعتمادًا.', 'icon' => 'calendar-event-fill', 'tone' => 'warning', 'url' => $requestsIndexUrl];
        if ($expiringDocuments->isNotEmpty()) $attentionItems[] = ['key' => 'documents-expiring', 'title' => 'مستندات أو عقود تنتهي قريبًا', 'count' => $expiringDocuments->count(), 'description' => 'تنتهي خلال 30 يومًا أو أقل.', 'icon' => 'file-earmark-excel-fill', 'tone' => 'danger', 'url' => route('documents.index')];
        if ($documents && $documents['incomplete']) $attentionItems[] = ['key' => 'documents-incomplete', 'title' => 'ملفات موظفين غير مكتملة', 'count' => $documents['incomplete'], 'description' => 'ينقصها مستند مطلوب واحد أو أكثر.', 'icon' => 'folder-x', 'tone' => 'warning', 'url' => route('employees.index')];

        $visibleModules = array_keys(array_filter(['employees' => $can('employees.view'), 'attendance' => $can('attendance.view'), 'leaves' => $can('leaves.view'), 'documents' => $can('documents.view'), 'payroll' => $can('payroll.view'), 'movements' => $can('movements.view')]));
        $activities = $visibleModules ? ActivityLog::with('user')->whereIn('module', $visibleModules)->latest()->limit(6)->get() : collect();
        $quickActions = array_values(array_filter([
            $can('employees.create') ? ['label' => 'موظف جديد', 'icon' => 'person-plus-fill', 'url' => route('employees.index', ['create' => 1])] : null,
            $can('leaves.create') ? ['label' => 'تسجيل إجازة', 'icon' => 'calendar-plus-fill', 'url' => route('leaves.index', ['create' => 1])] : null,
            $can('attendance.create') ? ['label' => 'تسجيل حضور', 'icon' => 'clock-fill', 'url' => route('attendance.index', ['create' => 1])] : null,
            $can('penalties.create') ? ['label' => 'تسجيل جزاء', 'icon' => 'gavel', 'url' => route('discipline.index', ['create' => 1])] : null,
            $can('documents.view') ? ['label' => 'المستندات', 'icon' => 'folder2-open', 'url' => route('documents.index')] : null,
            $can('requests.create') ? ['label' => 'طلب HR جديد', 'icon' => 'file-earmark-plus-fill', 'url' => route('requests.index', ['create' => 1])] : null,
            $can('payroll.create') ? ['label' => 'إصدار مسير الرواتب', 'icon' => 'currency-dollar', 'url' => route('payroll.index', ['month' => $month->format('Y-m-01')])] : null,
            $can('reports.view') ? ['label' => 'تصدير تقرير', 'icon' => 'bar-chart-line-fill', 'url' => route('reports.index')] : null,
        ]));

        $payrollYears = $can('payroll.view') ? \App\Models\PayrollRun::query()->selectRaw('YEAR(payroll_month) year')->distinct()->orderByDesc('year')->pluck('year') : collect();
        if ($payrollYears->isEmpty()) $payrollYears = collect([$today->year]);
        return view('dashboard.index', compact('today', 'month', 'heroMetrics', 'attendance', 'payroll', 'documents', 'expiringDocuments', 'pendingRequests', 'requestsIndexUrl', 'recentLeaves', 'recentEmployees', 'recentMovements', 'attentionItems', 'activities', 'quickActions', 'payrollYears'));
    }
}
