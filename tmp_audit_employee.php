<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$employees=App\Models\Employee::withoutGlobalScopes()->where('employee_code','1155')->get();
foreach($employees as $e){
 echo "EMP ".json_encode($e->only(['id','employee_code','full_name','company_id']),JSON_UNESCAPED_UNICODE).PHP_EOL;
 $requests=App\Models\HrRequest::withoutGlobalScopes()->with('requestType')->where('employee_id',$e->id)->get();
 echo "REQUESTS ".json_encode($requests->map(fn($r)=>['id'=>$r->id,'company_id'=>$r->company_id,'status'=>$r->status,'type'=>$r->request_type,'source'=>$r->requestType?->source_key,'payload'=>$r->payload])->values(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
 $records=App\Models\PayrollRecord::withoutGlobalScopes()->where('employee_id',$e->id)->get(['id','company_id','payroll_run_id','calculation_snapshot']);
 echo "PAYROLL ".json_encode($records->map(fn($r)=>['id'=>$r->id,'company_id'=>$r->company_id,'run'=>$r->payroll_run_id,'advance_details'=>data_get($r->calculation_snapshot,'advance_details',[])])->values(),JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;
}
