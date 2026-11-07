<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\{DashboardController, EmployeeController, AttendanceController, LeaveController, DocumentController, PenaltyController, HrRequestController};
use App\Models\{Employee, PayrollRecord, PayrollRun};
use Illuminate\Http\Request;

/** JSON projections of existing web use cases. No payroll calculations on reads. */
class ReadController extends Controller
{
    private function viewData(string $controller, Request $request): array
    {
        return app()->call([app($controller), 'index'], ['request' => $request])->getData();
    }

    public function dashboard(Request $request)
    {
        $data = $this->viewData(DashboardController::class, $request);
        return response()->json(['date' => $data['today']->toDateString(), 'metrics' => collect($data['heroMetrics'])->map(fn ($m) => collect($m)->only(['key', 'label', 'value', 'hint']))->values(), 'attention' => collect($data['attentionItems'])->map(fn ($m) => collect($m)->only(['key', 'title', 'count', 'description']))->values()]);
    }

    public function employees(Request $request)
    {
        $data = $this->viewData(EmployeeController::class, $request);
        return $this->page($data['employees'], fn ($e) => $this->employee($e), ['summary' => $data['employeeSummary']]);
    }

    private function employee(Employee $e): array
    {
        return ['id' => $e->id, 'title' => $e->full_name, 'subtitle' => implode(' • ', array_filter([$e->employee_code, $e->job?->title, $e->department?->name])), 'status' => $e->status?->name,
            'fields' => ['الكود' => $e->employee_code, 'القسم' => $e->department?->name, 'الوظيفة' => $e->job?->title, 'الهاتف' => $e->phone, 'البريد' => $e->email, 'بداية العقد' => $e->contract_start_date?->format('Y-m-d'), 'نهاية العقد' => $e->contract_end_date?->format('Y-m-d'), 'الإجمالي المتفق عليه' => $e->gross_salary],
            'edit' => $e->only(['full_name', 'employee_code', 'phone', 'email', 'department_id', 'job_id', 'branch_id', 'status_id', 'contract_start_date', 'contract_end_date'])];
    }

    public function employeeShow(Employee $employee)
    {
        return response()->json(['data' => $this->employee($employee->load(['department','job','status']))]);
    }

    public function attendance(Request $request)
    {
        $data = $this->viewData(AttendanceController::class, $request);
        return $this->page($data['attendances'], function ($r) {
            $model = $r['model'];
            return ['id' => $model?->id, 'title' => $r['employee']?->full_name, 'subtitle' => $r['date'], 'status' => $r['status_label'] ?? $model?->status,
                'fields' => ['التاريخ' => $r['date'], 'الحضور' => $model?->check_in ?: 'غير مسجل', 'الانصراف' => $model?->check_out ?: 'غير مسجل', 'تأخير (دقيقة)' => $model?->late_minutes, 'المصدر' => $model?->source],
                'edit' => ['employee_id' => $r['employee']?->id, 'attendance_date' => $r['date'], 'check_in' => $model?->check_in, 'check_out' => $model?->check_out]];
        }, ['summary' => ['present' => $data['presentToday'], 'absent' => $data['absentToday'], 'late' => $data['lateToday']]]);
    }

    public function leaves(Request $request)
    {
        $data = $this->viewData(LeaveController::class, $request);
        return $this->page($data['leaves'], fn ($l) => ['id' => $l->id, 'title' => $l->employee?->full_name, 'subtitle' => $l->leaveType?->name, 'status' => $l->status,
            'fields' => ['من' => $l->start_date?->format('Y-m-d'), 'إلى' => $l->end_date?->format('Y-m-d'), 'أيام العمل' => $l->days, 'السبب' => $l->reason, 'سبب الرفض' => $l->rejection_reason],
            'edit' => $l->only(['employee_id','leave_type_id','start_date','end_date','reason'])]);
    }

    public function documents(Request $request)
    {
        $data = $this->viewData(DocumentController::class, $request);
        return $this->page($data['documents'], fn ($d) => ['id' => $d->id, 'title' => $d->document_name, 'subtitle' => $d->employee?->full_name, 'status' => $d->status, 'file_name' => $d->file_name, 'has_file' => (bool) $d->file_path,
            'fields' => ['النوع' => $d->type?->name ?: $d->document_type, 'تاريخ الإصدار' => $d->issue_date?->format('Y-m-d'), 'الانتهاء' => $d->expiry_date?->format('Y-m-d'), 'الحجم' => $d->formatted_size, 'ملاحظات' => $d->notes]]);
    }

    public function penalties(Request $request)
    {
        $data = $this->viewData(PenaltyController::class, $request);
        return $this->page($data['penalties'], fn ($p) => ['id' => $p->id, 'title' => $p->employee?->full_name, 'subtitle' => $p->title, 'status' => $p->status,
            'fields' => ['التاريخ' => $p->penalty_date?->format('Y-m-d'), 'السبب' => $p->reason, 'القيمة' => $p->amount, 'أيام الخصم' => $p->deduction_days],
            'edit' => $p->only(['employee_id','penalty_type_id','penalty_date','reason','amount','deduction_days'])]);
    }

    public function requests(Request $request)
    {
        $data = $this->viewData(HrRequestController::class, $request);
        return $this->page($data['requests'], fn ($r) => ['id' => $r->id, 'title' => $r->title, 'subtitle' => $r->employee?->full_name, 'status' => $r->status,
            'fields' => ['النوع' => $r->requestType?->display_name ?: $r->request_type, 'التفاصيل' => $r->details, 'القيمة' => $r->display_value, 'سبب الرفض' => $r->rejection_reason]]);
    }

    public function payroll(Request $request)
    {
        $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = ($request->input('month') ?: today()->format('Y-m')).'-01';
        // Read saved server records only. Viewing the mobile screen never generates/recalculates a run.
        $query = PayrollRecord::with('employee')->whereDate('payroll_month', $month);
        $summary = ['month' => substr($month,0,7), 'employees' => (clone $query)->count(), 'net' => (clone $query)->sum('net_salary'), 'cost' => (clone $query)->sum('employee_cost')];
        $run = PayrollRun::whereDate('payroll_month', $month)->first();
        $reconciliation = $run ? app(\App\Services\PayrollRunService::class)->reconciliationReport($run) : null;
        return $this->page($query->orderBy('id')->paginate(25), fn ($p) => ['id' => $p->id, 'title' => $p->employee?->full_name, 'subtitle' => $p->payroll_month?->format('Y-m'), 'status' => $p->status, 'is_locked'=>$p->is_locked,'paid_at'=>$p->paid_at,
            'edit' => ['ot_day_hours'=>$p->ot_day_hours,'ot_night_hours'=>$p->ot_night_hours,'bonus'=>$p->bonus,'loans'=>$p->loans,'other_earnings'=>$p->other_earnings,'other_deductions'=>$p->other_deductions],
            'fields' => ['الأساسي' => $p->basic_salary, 'البدلات' => $p->allowances, 'الإضافي' => $p->total_overtime, 'خصم الغياب' => $p->absence_deduction, 'خصم الجزاءات' => $p->penalty_deduction, 'التأمينات' => $p->employee_insurance, 'الضريبة' => $p->income_tax, 'صندوق الشهداء' => $p->martyrs_fund, 'الصافي' => $p->net_salary, 'مقفل' => $p->is_locked ? 'نعم' : 'لا']], ['summary' => $summary, 'reconciliation' => $reconciliation, 'run' => $run?->only(['id','status','period_start','period_end','closes_at','skipped_report'])]);
    }

    public function notifications(Request $request)
    {
        return $this->page($request->user()->notifications()->latest()->paginate(25), fn ($n) => ['id' => $n->id, 'title' => data_get($n->data,'title','إشعار'), 'subtitle' => data_get($n->data,'message',data_get($n->data,'body','')), 'status' => $n->read_at ? 'مقروء' : 'جديد', 'fields' => ['التاريخ' => $n->created_at?->toDateTimeString()]]);
    }

    public function summary(Request $request)
    {
        $request->validate(['month'=>'nullable|date_format:Y-m']);
        $data=$this->viewData(\App\Http\Controllers\JobDataController::class,$request);
        $labels=['attendance_days'=>'أيام الحضور','late_days'=>'أيام التأخير','absence_days'=>'أيام الغياب','early_leave_minutes'=>'انصراف مبكر (دقيقة)','day_overtime_minutes'=>'إضافي نهاري (دقيقة)','night_overtime_minutes'=>'إضافي ليلي (دقيقة)','annual_leave_days'=>'إجازة اعتيادية','casual_leave_days'=>'إجازة عارضة','unpaid_leave_days'=>'إجازة بدون أجر','penalty_days'=>'أيام الجزاءات','annual_balance_remaining'=>'رصيد الإجازة في نهاية الفترة','advance_request_count'=>'طلبات السلف','permission_request_count'=>'طلبات الأذون','mission_request_count'=>'طلبات المأموريات'];
        $rows=$data['monthlyRows']->keyBy(fn($r)=>$r['employee']->id);
        return $this->page($data['employees'],function($e)use($rows,$labels){$r=$rows->get($e->id);$fields=[];foreach($labels as $key=>$label)$fields[$label]=$r[$key]??null;return ['id'=>$e->id,'title'=>$e->full_name,'subtitle'=>$e->employee_code,'status'=>'','fields'=>$fields];});
    }

    public function reports(Request $request)
    {
        $request->validate(['type'=>'nullable|in:employees,attendance,leaves,payroll,insurance,summary', 'page'=>'nullable|integer|min:1','month'=>'nullable|date_format:Y-m', 'from_date'=>'nullable|date','to_date'=>'nullable|date|after_or_equal:from_date']);
        $type=$request->input('type','summary');
        $view=app(\App\Http\Controllers\ReportController::class)->show($request,$type);
        $data=$view->getData();$rows=collect($data['rows'])->values();
        $page=new \Illuminate\Pagination\LengthAwarePaginator($rows->forPage((int)$request->input('page',1),25)->values(),$rows->count(),25,(int)$request->input('page',1));
        return $this->page($page,fn($row)=>['id'=>null,'title'=>(string)($row[0]??'سجل'),'subtitle'=>$data['title'],'status'=>'','fields'=>array_combine($data['columns'],array_values($row))],['report_title'=>$data['title']]);
    }

    private function page($page, callable $map, array $extra = [])
    {
        return response()->json(['data' => $page->getCollection()->map($map)->values(), 'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total(), ...$extra]);
    }

    public function lookups(Request $request)
    {
        $user = $request->user();
        $module = $request->validate(['module'=>'required|in:employees,attendance,leaves,penalties,documents,requests'])['module'];
        abort_unless($user->hasPermission($module.'.view') || $user->hasPermission($module.'.create') || $user->hasPermission($module.'.edit') || ($module === 'documents' && $user->hasPermission('documents.upload')), 403);
        if ($module === 'requests') {
            $data = $this->viewData(HrRequestController::class, $request);
            return response()->json(['request_types' => $data['requestTypes']->map(fn($t)=>['id'=>$t->id,'name'=>$t->display_name,'source'=>$t->source_key])->values()]);
        }
        $models = match($module) {
            'employees' => ['departments'=>\App\Models\Department::class,'jobs'=>\App\Models\Job::class,'branches'=>\App\Models\Branch::class,'statuses'=>\App\Models\EmployeeStatus::class,'employment_types'=>\App\Models\EmploymentType::class,'banks'=>\App\Models\Bank::class,'payment_methods'=>\App\Models\PaymentMethod::class],
            'leaves' => ['leave_types'=>\App\Models\LeaveType::class],
            'penalties' => ['penalty_types'=>\App\Models\PenaltyType::class],
            'documents' => ['document_types'=>\App\Models\DocumentType::class],
            default => [],
        };
        $data=[];
        foreach($models as $key=>$model) {
            $column = $model === \App\Models\Job::class ? 'title' : 'name';
            $data[$key]=$model::orderBy($column)->get(['id',$column])->map(fn($row)=>['id'=>$row->id,'name'=>$row->{$column}]);
        }
        return response()->json($data);
    }

    public function people(Request $request)
    {
        $request->validate(['search'=>'nullable|string|max:100','module'=>'required|in:employees,attendance,leaves,penalties,documents,requests']);
        $module=$request->string('module')->toString();
        abort_unless($request->user()->hasPermission($module.'.create') || $request->user()->hasPermission($module.'.edit') || ($module==='documents' && $request->user()->hasPermission('documents.upload')),403);
        $query=Employee::orderBy('full_name');
        if ($module === 'requests') {
            $user=$request->user();
            if ($user->role?->name==='employee') $query->whereKey($user->employee_id ?: 0);
            elseif ($user->role?->name==='manager' && $user->employee_id) $query->where(fn($q)=>$q->whereKey($user->employee_id)->orWhere('manager_employee_id',$user->employee_id));
        }
        if($request->filled('search')) $query->where(fn($q)=>$q->where('full_name','like','%'.$request->input('search').'%')->orWhere('employee_code','like','%'.$request->input('search').'%'));
        $page=$query->paginate(25,['id','full_name','employee_code']);
        return response()->json($page);
    }
}
