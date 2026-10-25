<?php
require __DIR__.'/vendor/autoload.php';
$app=require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$email='maers@local.com';
$companies=DB::select('SELECT id,name,email FROM companies WHERE email LIKE ? OR id IN (SELECT company_id FROM users WHERE email LIKE ?)',["%maers%","%maers%"]);
if (!$companies) { echo "NO_COMPANY_MATCH\n"; foreach(DB::select('SELECT id,name,email FROM companies') as $x) echo json_encode($x,JSON_UNESCAPED_UNICODE).PHP_EOL; foreach(DB::select('SELECT id,email,company_id FROM users') as $x) echo json_encode($x,JSON_UNESCAPED_UNICODE).PHP_EOL; }
$companies=DB::select('SELECT id,name,email FROM companies WHERE id IN (SELECT company_id FROM employees WHERE employee_code=?)',['107']);
foreach($companies as $c){
 echo "COMPANY ".json_encode($c,JSON_UNESCAPED_UNICODE).PHP_EOL;
 $employees=DB::select('SELECT * FROM employees WHERE company_id=? AND employee_code=?',[$c->id,'107']);
 foreach($employees as $e){
  echo "EMPLOYEE ".json_encode($e,JSON_UNESCAPED_UNICODE).PHP_EOL;
  foreach(DB::select('SELECT * FROM salaries WHERE employee_id=? ORDER BY effective_from DESC,id DESC',[$e->id]) as $s) echo "SALARY ".json_encode($s,JSON_UNESCAPED_UNICODE).PHP_EOL;
  foreach(DB::select('SELECT pr.*, pr.payroll_month, pu.status run_status FROM payroll_records pr JOIN payroll_runs pu ON pu.id=pr.payroll_run_id WHERE pr.employee_id=? ORDER BY pr.payroll_month DESC,pr.id DESC LIMIT 12',[$e->id]) as $p) echo "PAYROLL ".json_encode($p,JSON_UNESCAPED_UNICODE).PHP_EOL;
 }
}
