<?php
namespace App\Http\Controllers;
use App\Models\{Attendance,AttendanceImportBatch,AttendanceImportDay,AttendancePunchRevision};
use App\Services\{AttendanceMetricsService,AttendancePeriodArchiveService,PayrollPeriodGuard,PayrollRunService};
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class AttendanceExceptionController extends Controller
{
    public function index(Request $request) {
        $query = AttendanceImportDay::with('employee')->where('resolution','pending');
        if ($request->filled('from')) $query->whereDate('work_date','>=',$request->validate(['from'=>'date'])['from']);
        if ($request->filled('to')) $query->whereDate('work_date','<=',$request->validate(['to'=>'date'])['to']);
        return view('attendance.exceptions.index', ['days'=>$query->orderBy('work_date')->paginate(30),'count'=>AttendanceImportDay::where('resolution','pending')->count(),'batches'=>AttendanceImportBatch::latest()->limit(20)->get()]);
    }
    public function show(AttendanceImportDay $day) {
        $day->load(['employee','batch','revisions.user']);
        $editable=true;
        try { app(PayrollPeriodGuard::class)->ensureOpen($day->work_date); app(AttendancePeriodArchiveService::class)->ensureDateOpen($day->work_date); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException) { $editable=false; }
        return view('attendance.exceptions.show',compact('day','editable'));
    }
    public function batch(AttendanceImportBatch $batch) {
        $source=$batch->original_data;
        $columns=array_values(array_unique(array_map('intval',array_values($source['map'] ?? []))));
        $mappedSheets=[];
        foreach ($source['sheets'] ?? [] as $sheet) {
            $safeRows=[];
            // Do not expose unassigned cells, metadata/header notes, salary or national IDs.
            $definition=collect($source['unit']['sheets'] ?? [])->firstWhere('name',$sheet['name'] ?? '');
            $header=(int)($definition['header_row'] ?? 0);
            foreach ($sheet['rows'] ?? [] as $line=>$row) {
                if ($line<$header) continue;
                $safeRows[$line]=array_map(fn ($column) => $row[$column] ?? '',$columns);
            }
            $mappedSheets[]=['name'=>$sheet['name'] ?? '', 'rows'=>$safeRows];
        }
        return view('attendance.exceptions.batch',compact('batch','mappedSheets'));
    }
    public function update(Request $request, AttendanceImportDay $day) {
        $data = $request->validate([
            'punches'=>['nullable','array','max:100'], 'punches.*'=>['required','date_format:Y-m-d\\TH:i'],
            'check_in_index'=>['nullable','integer','min:0'], 'check_out_index'=>['nullable','integer','min:0'],
            'version'=>['required','integer','min:0'], 'reason'=>['required','string','max:1000'], 'overwrite'=>['sometimes','accepted'],
        ]);
        $punches = array_values($data['punches'] ?? []);
        $start = $day->work_date->copy()->startOfDay()->subHours(4);
        $end = $day->work_date->copy()->startOfDay()->addDays(2);
        foreach ($punches as $punch) abort_unless(Carbon::parse($punch)->betweenIncluded($start,$end),422,__('البصمة خارج يوم العمل ونطاق الوردية الليلية.'));
        $hasIn = isset($data['check_in_index']); $hasOut = isset($data['check_out_index']);
        abort_if($hasIn !== $hasOut,422,__('حدد بصمة الحضور والانصراف معًا أو اترك اليوم للمراجعة.'));
        if ($hasIn) {
            $in = $punches[$data['check_in_index']] ?? null; $out = $punches[$data['check_out_index']] ?? null;
            abort_unless($in && $out && Carbon::parse($out)->gt(Carbon::parse($in)) && Carbon::parse($out)->diffInHours(Carbon::parse($in),true) <= 24,422,__('اختيار الحضور والانصراف غير صالح.'));
            $inAt=Carbon::parse($in); $outAt=Carbon::parse($out);
            abort_unless($inAt->toDateString()===$day->work_date->toDateString() && $outAt->toDateString()===($outAt->format('H:i') < $inAt->format('H:i') ? $day->work_date->copy()->addDay()->toDateString() : $day->work_date->toDateString()),422,__('تاريخ البصمات المختارة لا يطابق يوم العمل أو عبور منتصف الليل.'));
        }
        DB::transaction(function () use ($day,$request,$data,$punches,$hasIn) {
            $archive = app(AttendancePeriodArchiveService::class);
            $archive->lockCompanyForWrite((int)$day->company_id);
            $day = AttendanceImportDay::lockForUpdate()->findOrFail($day->id);
            app(PayrollPeriodGuard::class)->ensureOpen($day->work_date); $archive->ensureDateOpen($day->work_date);
            abort_if(isset($data['version']) && (int)$data['version'] !== $day->revisions()->count(),409,'اتغير اليوم بعد فتحه؛ أعد تحميل الصفحة.');
            abort_if($day->resolution==='deleted',422,__('تم حذف هذا اليوم؛ لا يمكن تعديل سجل محذوف.'));
            $old = ['punches'=>$day->current_punches,'resolution'=>$day->resolution];
            if (count($punches) < count($day->current_punches)) abort_unless($request->user()->hasPermission('attendance.delete'),403,__('إزالة بصمة تتطلب صلاحية الحذف.'));
            $existing = Attendance::where('employee_id',$day->employee_id)->whereDate('attendance_date',$day->work_date)->lockForUpdate()->first();
            if ($existing) abort_unless($request->boolean('overwrite'),422,__('أكد تحديث سجل الحضور الموجود.'));
            if ($hasIn) {
                abort_unless($request->user()->hasPermission($existing ? 'attendance.edit':'attendance.create'),403);
                $in = Carbon::parse($punches[$data['check_in_index']])->format('H:i');
                $out = Carbon::parse($punches[$data['check_out_index']])->format('H:i');
                $metrics = app(AttendanceMetricsService::class)->metrics($day->employee,$in,$out,'Present');
                $attendance = Attendance::updateOrCreate(['employee_id'=>$day->employee_id,'attendance_date'=>$day->work_date->toDateString()],['check_in'=>$in,'check_out'=>$out,...$metrics,'source'=>'import','approval_status'=>'Approved']);
                $day->attendance_id = $attendance->id;
            } elseif ($existing) {
                // Pending corrections must not leave an old derived day active silently.
                abort_unless($request->user()->hasPermission('attendance.delete'),403);
                $existing->delete(); $day->attendance_id = null;
            }
            $day->current_punches = $punches;
            $day->resolution = $hasIn ? 'resolved':'pending';
            $day->issue = $hasIn ? null : (count($punches)>2 ? 'extra':'missing');
            $day->save();
            AttendancePunchRevision::create(['import_day_id'=>$day->id,'user_id'=>$request->user()->id,'action'=>'correct','reason'=>$data['reason'],'before_data'=>$old,'after_data'=>['punches'=>$punches,'resolution'=>$day->resolution,'selected'=>[$data['check_in_index']??null,$data['check_out_index']??null]],'created_at'=>now()]);
        });
        return $this->finish($day,'تم حفظ التصحيح وإعادة فحص اليوم.');
    }
    public function destroy(Request $request, AttendanceImportDay $day) {
        $data = $request->validate(['version'=>['required','integer','min:0'],'reason'=>['required','string','max:1000'],'delete_attendance'=>['sometimes','accepted']]);
        DB::transaction(function () use ($day,$data,$request) {
            $archive = app(AttendancePeriodArchiveService::class); $archive->lockCompanyForWrite((int)$day->company_id);
            $day=AttendanceImportDay::lockForUpdate()->findOrFail($day->id);
            app(PayrollPeriodGuard::class)->ensureOpen($day->work_date); $archive->ensureDateOpen($day->work_date);
            abort_if((int)$data['version'] !== $day->revisions()->count(),409,'اتغير اليوم بعد فتحه؛ أعد تحميل الصفحة.');
            abort_if($day->resolution==='deleted',422,__('هذا اليوم محذوف بالفعل.'));
            $existing = Attendance::where('employee_id',$day->employee_id)->whereDate('attendance_date',$day->work_date)->lockForUpdate()->first();
            abort_if($existing && !$request->boolean('delete_attendance'),422,__('أكد حذف سجل الحضور المرتبط أيضًا.'));
            $old=['punches'=>$day->current_punches,'resolution'=>$day->resolution,'attendance'=>$existing?->toArray()];
            if ($existing) $existing->delete();
            $day->update(['current_punches'=>[],'resolution'=>'deleted','attendance_id'=>null]);
            AttendancePunchRevision::create(['import_day_id'=>$day->id,'user_id'=>$request->user()->id,'action'=>'delete','reason'=>$data['reason'],'before_data'=>$old,'after_data'=>['resolution'=>'deleted'],'created_at'=>now()]);
        });
        return $this->finish($day,'تم استبعاد اليوم مع الاحتفاظ بالأصل وسجل الحذف.');
    }
    private function finish(AttendanceImportDay $day,string $message) {
        try { app(PayrollRunService::class)->refreshEmployeeInOpenDrafts((int)$day->employee_id); }
        catch (\Throwable) { $message .= ' تعذر تحديث مسودة الراتب؛ أعد حساب المسودة.'; }
        return redirect()->route('attendance.exceptions.show',$day)->with('toast',$message);
    }
}
