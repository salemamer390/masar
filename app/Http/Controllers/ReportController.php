<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Department;
use App\Models\Employee;
use App\Models\InsuranceRecord;
use App\Models\Leave;
use App\Models\PayrollRecord;
use App\Models\Salary;
use Carbon\Carbon;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function index()
    {
        return view('reports.index');
    }

    public function show(Request $request, string $type)
    {
        $data = $this->getReportData($type, $request);
        $data['title'] = __($data['title']);
        $data['subtitle'] = __($data['subtitle']);
        $data['columns'] = array_map(fn ($column) => __($column), $data['columns'] ?? []);
        return view('reports.show', array_merge(['type' => $type, 'departments' => Department::where('is_active', 1)->orderBy('name')->get()], $data));
    }

    public function export(Request $request, string $type)
    {
        // The payroll report must use the same company-scoped records and
        // Excel template as the payroll page export. Keeping one generator
        // prevents the report card from producing a different (CSV) result.
        if ($type === 'payroll') {
            // Use the same export data as the payroll page, including draft
            // records that are still being prepared for the selected month.
            $request->merge(['format' => 'xlsx']);

            return app(PayrollController::class)->export($request);
        }

        $data = $this->getReportData($type, $request);
        $title = $data['title'] ?? 'report';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$type}_report.csv\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ];

        return response()->stream(function () use ($data) {
            $handle = fopen('php://output', 'w');
            // Add UTF-8 BOM for proper Excel Arabic display
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            if (!empty($data['columns'])) {
                fputcsv($handle, $data['columns']);
            }

            foreach ($data['rows'] as $row) {
                fputcsv($handle, \App\Support\CsvSafe::row($row));
            }

            fclose($handle);
        }, 200, $headers);
    }

    private function getReportData(string $type, ?Request $request = null): array
    {
        switch ($type) {
            case 'employees':
                $items = Employee::with(['department', 'job', 'status'])
                    ->when($request?->filled('search'), fn ($q) => $q->where(function ($x) use ($request) { $x->where('full_name', 'like', '%'.$request->search.'%')->orWhere('employee_code', 'like', '%'.$request->search.'%'); }))
                    ->when($request?->filled('department_id'), fn ($q) => $q->where('department_id', $request->department_id))->get();
                return [
                    'title' => 'تقرير الموظفين',
                    'subtitle' => 'بيانات الموظفين والحالات والأقسام',
                    'columns' => ['الكود', 'الاسم', 'الوظيفة', 'القسم', 'تاريخ بداية العقد', 'الحالة'],
                    'rows' => $items->map(fn($e) => [
                        $e->employee_code,
                        $e->full_name,
                        $e->job?->title ?? '—',
                        $e->department?->name ?? '—',
                        $e->hire_date?->format('d/m/Y') ?? '—',
                        $e->status?->name ?? 'نشط',
                    ]),
                    'items' => $items,
                ];

            case 'attendance':
                $items = Attendance::with(['employee.department', 'employee.job'])
                    ->when($request?->filled('from_date'), fn ($q) => $q->whereDate('attendance_date', '>=', $request->from_date))
                    ->when($request?->filled('to_date'), fn ($q) => $q->whereDate('attendance_date', '<=', $request->to_date))
                    ->when($request?->filled('search'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('full_name', 'like', '%'.$request->search.'%')->orWhere('employee_code', 'like', '%'.$request->search.'%')))
                    ->when($request?->filled('department_id'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))
                    ->latest('attendance_date')->take(200)->get();
                return [
                    'title' => 'تقرير الحضور والانصراف',
                    'subtitle' => 'التأخير والغياب وساعات العمل',
                    'columns' => ['الموظف', 'التاريخ', 'الحضور', 'الانصراف', 'التأخير', 'الحالة'],
                    'rows' => $items->map(fn($a) => [
                        $a->employee?->full_name ?? '—',
                        $a->attendance_date->format('d/m/Y'),
                        $a->check_in ?? '—',
                        $a->check_out ?? '—',
                        $a->formatted_late,
                        $a->status,
                    ]),
                    'items' => $items,
                ];

            case 'salaries':
                $items = Salary::with(['employee.department', 'employee.job', 'paymentMethod'])->where('status', 'Active')
                    ->when($request?->filled('search'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('full_name', 'like', '%'.$request->search.'%')->orWhere('employee_code', 'like', '%'.$request->search.'%')))
                    ->when($request?->filled('department_id'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))->get();
                return [
                    'title' => 'تقرير الرواتب',
                    'subtitle' => 'الصافي المتفق عليه والإجمالي والتأمينات والضريبة وتكلفة الموظف',
                    'columns' => ['الموظف', 'الوظيفة', 'القسم', 'الصافي المتفق عليه', 'الأجر الأساسي', 'البدلات', 'الإجمالي', 'تأمين الموظف', 'الضريبة', 'الصافي النهائي', 'تكلفة الموظف'],
                    'rows' => $items->map(fn($s) => [
                        $s->employee?->full_name ?? '—',
                        $s->employee?->job?->title ?? '—',
                        $s->employee?->department?->name ?? '—',
                        format_money($s->salary_input),
                        format_money($s->basic_salary),
                        format_money($s->allowances),
                        format_money($s->gross_salary),
                        format_money($s->employee_insurance),
                        format_money($s->income_tax),
                        format_money($s->net_salary),
                        format_money($s->employee_cost),
                    ]),
                    'items' => $items,
                ];

            case 'leaves':
                $items = Leave::with(['employee.department', 'leaveType'])
                    ->when($request?->filled('from_date'), fn ($q) => $q->whereDate('start_date', '>=', $request->from_date))
                    ->when($request?->filled('to_date'), fn ($q) => $q->whereDate('end_date', '<=', $request->to_date))
                    ->when($request?->filled('search'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('full_name', 'like', '%'.$request->search.'%')->orWhere('employee_code', 'like', '%'.$request->search.'%')))
                    ->when($request?->filled('department_id'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))
                    ->latest('start_date')->take(200)->get();
                return [
                    'title' => 'تقرير الإجازات',
                    'subtitle' => 'الأرصدة والطلبات والحالات',
                    'columns' => ['الموظف', 'نوع الإجازة', 'من', 'إلى', 'الأيام', 'الحالة'],
                    'rows' => $items->map(fn($l) => [
                        $l->employee?->full_name ?? '—',
                        $l->leaveType?->name ?? 'سنوية',
                        $l->start_date->format('d/m/Y'),
                        $l->end_date->format('d/m/Y'),
                        $l->days,
                        $l->status,
                    ]),
                    'items' => $items,
                ];

            case 'insurance':
                $items = InsuranceRecord::with(['employee.department', 'employee.job'])
                    ->when($request?->filled('search'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('full_name', 'like', '%'.$request->search.'%')->orWhere('employee_code', 'like', '%'.$request->search.'%')))
                    ->when($request?->filled('status'), fn ($q) => $q->where('status', $request->status))->get();
                return [
                    'title' => 'تقرير التأمينات',
                    'subtitle' => 'حالة التسجيل وأكواد المهن',
                    'columns' => ['الموظف', 'رقم التأمين', 'كود المهنة', 'تاريخ التسجيل', 'الحالة'],
                    'rows' => $items->map(fn($i) => [
                        $i->employee?->full_name ?? '—',
                        $i->insurance_number ?? '—',
                        $i->job_code ?? '—',
                        $i->insurance_start_date?->format('d/m/Y') ?? '—',
                        $i->status,
                    ]),
                    'items' => $items,
                ];

            case 'payroll':
                $companyId = auth()->user()?->company_id;
                $reportMonth = $request?->filled('month')
                    ? Carbon::parse($request->input('month'))->startOfMonth()
                    : now()->startOfMonth();
                $items = PayrollRecord::with(['employee.department', 'employee.job'])
                    ->when($request?->filled('search'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('full_name', 'like', '%'.$request->search.'%')->orWhere('employee_code', 'like', '%'.$request->search.'%')))
                    ->when($request?->filled('department_id'), fn ($q) => $q->whereHas('employee', fn ($e) => $e->where('department_id', $request->department_id)))
                    ->whereIn('status', in_array($request?->input('status'), ['Approved', 'Locked'], true) ? [$request->input('status')] : ['Approved', 'Locked'])
                    ->when($companyId, fn ($q) => $q->where('company_id', $companyId))
                    ->whereDate('payroll_month', $reportMonth->toDateString())
                    ->latest('payroll_month')->get();
                return [
                    'title' => 'تقرير مسير الرواتب الشهري',
                    'subtitle' => 'سجلات المسير المعتمدة والمقفلة فقط.',
                    'columns' => ['الشهر', 'الموظف', 'القسم', 'الإجمالي', 'الصافي', 'تكلفة الشركة', 'الحالة'],
                    'rows' => $items->map(fn ($record) => [
                        $record->payroll_month?->format('Y-m'), $record->employee?->full_name ?? '—',
                        $record->employee?->department?->name ?? '—', format_money($record->gross_salary),
                        format_money($record->net_salary), format_money($record->employee_cost), $record->status,
                    ]),
                    'items' => $items,
                ];

            case 'summary':
            default:
                $totalEmployees = Employee::count();
                $activeEmployees = Employee::count();
                $totalSalaries = Salary::where('status', 'Active')->sum('net_salary');
                $totalLeaves = Leave::count();
                return [
                    'title' => 'التقرير الإداري الشامل',
                    'subtitle' => 'ملخص مؤشرات شؤون العاملين',
                    'columns' => ['المؤشر', 'القيمة'],
                    'rows' => collect([
                        ['إجمالي الموظفين', $totalEmployees],
                        ['الموظفون النشطون', $activeEmployees],
                        ['إجمالي فاتورة الرواتب', format_money($totalSalaries) . ' ج.م'],
                        ['إجمالي طلبات الإجازات', $totalLeaves],
                    ]),
                    'items' => collect([
                        ['metric' => 'إجمالي الموظفين', 'value' => $totalEmployees],
                        ['metric' => 'الموظفون النشطون', 'value' => $activeEmployees],
                        ['metric' => 'إجمالي فاتورة الرواتب', 'value' => format_money($totalSalaries) . ' ج.م'],
                        ['metric' => 'إجمالي طلبات الإجازات', 'value' => $totalLeaves],
                    ]),
                ];
        }
    }
}
