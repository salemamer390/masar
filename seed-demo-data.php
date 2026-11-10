<?php
/*
 * Masar HR demo dataset seeder — ISOLATED ENVIRONMENTS ONLY.
 * Never run this against a live/production database.
 *
 * Usage:  php seed-demo-data.php
 * Run from the application root (where artisan lives). The app must be
 * configured (its .env) to point at a THROWAWAY database, e.g. one created
 * with `php artisan migrate --seed` purely for demo/audit purposes.
 *
 * Safety guard: the script refuses to run when APP_ENV=production, or when
 * the connected database name does not look isolated (must contain
 * test/demo/sandbox/local, case-insensitive). To use a differently named
 * disposable database, confirm it explicitly:
 *   php seed-demo-data.php --allow-db=<exact database name>
 *
 * Idempotent: every row is check-then-insert inside one transaction, keyed on
 * natural unique markers (employee_code, employee+date, employee+year, ...).
 * Re-running completes missing pieces and never duplicates or deletes.
 * The script only INSERTs/UPDATEs nothing destructive; it contains no
 * credentials (connection comes from the app's own configuration).
 *
 * Covers: employees (with gross salary + manual annual balance), salary
 * structures, profile details, emergency contacts, bank accounts, attendance
 * states, leaves (incl. maternity), leave balances, HR requests, documents
 * (with real downloadable demo PDF files), insurance, penalties, movements,
 * payroll runs in three states (Draft, Approved, Locked) with their records,
 * a Draft end-of-service settlement, and required reference rows (leave
 * types, penalty/movement types, payment methods, bank).
 */

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\Employee;
use App\Services\PayrollCalculator;

// --- Safety guard: isolated databases only -----------------------------------
$dbName = (string) config('database.connections.mysql.database');
$explicitAllow = in_array('--allow-db='.$dbName, $argv ?? [], true);
$looksIsolated = (bool) preg_match('/test|demo|sandbox|local/i', $dbName);
if (env('APP_ENV') === 'production' || !($looksIsolated || $explicitAllow)) {
    fwrite(STDERR, "REFUSED: connected database '{$dbName}' is not a recognised isolated/test database.\n");
    fwrite(STDERR, "Point the app at a throwaway database (name containing test/demo/sandbox/local),\n");
    fwrite(STDERR, "or re-run with: php seed-demo-data.php --allow-db={$dbName}\n");
    exit(1);
}
echo "Seeding demo data into isolated database '{$dbName}'...\n";

$now = now();
$cid = 1;
$inserted = 0;
$skipped = 0;

/** check-then-insert helper */
$ensure = function (string $table, array $where, array $row) use (&$inserted, &$skipped) {
    $q = DB::table($table);
    foreach ($where as $col => $val) $q->where($col, $val);
    if ($q->exists()) { $skipped++; return null; }
    DB::table($table)->insert($row);
    $inserted++;
    return true;
};

/** Minimal valid one-page PDF so seeded documents are downloadable. */
$makePdf = function (string $title): string {
    $title = preg_replace('/[^\x20-\x7E]/', '', $title);
    $stream = "BT /F1 14 Tf 50 750 Td ({$title}) Tj ET\nBT /F1 10 Tf 50 730 Td (Masar HR demo document - isolated test data only.) Tj ET";
    $len = strlen($stream);
    $pdf = "%PDF-1.4\n";
    $o = [];
    $o[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $o[2] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $o[3] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n";
    $o[4] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
    $o[5] = "5 0 obj\n<< /Length {$len} >>\nstream\n{$stream}\nendstream\nendobj\n";
    $offsets = [];
    foreach ($o as $body) { $offsets[] = strlen($pdf); $pdf .= $body; }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 6\n0000000000 65535 f \n";
    foreach ($offsets as $off) $pdf .= sprintf("%010d 00000 n \n", $off);
    $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    return $pdf;
};

DB::transaction(function () use ($now, $cid, $ensure, $makePdf, &$inserted, &$skipped) {

    // --- Reference rows -------------------------------------------------------
    $leaveTypes = [
        ['name' => 'الإجازة السنوية',  'policy_code' => 'annual',    'deducts_annual_balance' => 1, 'is_paid' => 1, 'max_days_per_request' => 15,  'min_service_months' => 6],
        ['name' => 'الإجازة العارضة',  'policy_code' => 'casual',    'deducts_annual_balance' => 1, 'is_paid' => 1, 'max_days_per_request' => 2,   'max_days_per_year' => 7, 'min_service_months' => 6],
        ['name' => 'الإجازة المرضية',  'policy_code' => 'sick',      'deducts_annual_balance' => 0, 'is_paid' => 1, 'requires_document' => 1],
        ['name' => 'إجازة وضع',        'policy_code' => 'maternity', 'deducts_annual_balance' => 0, 'is_paid' => 1, 'max_days_per_request' => 120, 'max_occurrences_service' => 3, 'requires_document' => 1, 'eligibility_gender' => 'female'],
        ['name' => 'إجازة بدون أجر',   'policy_code' => 'unpaid',    'deducts_annual_balance' => 0, 'is_paid' => 0],
    ];
    foreach ($leaveTypes as $t) {
        $ensure('leave_types', ['company_id' => null, 'policy_code' => $t['policy_code']], array_merge(['company_id' => null, 'is_active' => 1], $t));
    }
    foreach (['Verbal Warning','Written Warning','Day Deduction','Salary Deduction','Notice','Suspension'] as $n) {
        $ensure('penalty_types', ['name' => $n], ['name' => $n]);
    }
    foreach (['Hire','Department Transfer','Promotion','Salary Adjustment','Branch Transfer','Job Title Change'] as $n) {
        $ensure('movement_types', ['name' => $n], ['name' => $n]);
    }
    foreach (['Bank','Cash','Wallet'] as $n) {
        $ensure('payment_methods', ['name' => $n], ['name' => $n]);
    }
    // employment_types are only created by the (currently broken) TazamunSeeder,
    // so a migrate-only database needs them here.
    foreach (['Full Time','Part Time','Fixed Contract','Internship','Freelance'] as $n) {
        $ensure('employment_types', ['name' => $n], ['name' => $n]);
    }
    $ensure('banks', ['name' => 'Demo Bank'], ['name' => 'Demo Bank', 'code' => 'DEMO']);

    $dept  = DB::table('departments')->pluck('id', 'name');
    $jobs  = DB::table('hr_jobs')->pluck('id', 'title');
    $br    = DB::table('branches')->pluck('id', 'name');
    $types = DB::table('employment_types')->pluck('id', 'name');
    $st    = DB::table('employee_statuses')->pluck('id', 'name');
    $lt    = DB::table('leave_types')->whereNull('company_id')->pluck('id', 'policy_code');
    $pm    = DB::table('payment_methods')->pluck('id', 'name');
    $bank  = DB::table('banks')->pluck('id', 'name');
    $pt    = DB::table('penalty_types')->pluck('id', 'name');
    $mt    = DB::table('movement_types')->pluck('id', 'name');
    $dt    = DB::table('document_types')->pluck('id', 'name');

    // --- Employees --------------------------------------------------------------
    $calc = new PayrollCalculator();
    $grossOf = function (float $net) use ($calc) {
        return $calc->structure(['salary_type' => 'net', 'salary_input' => $net])['gross'] ?? null;
    };

    $mk = function (array $d) use ($cid, $dept, $jobs, $br, $types, $st, &$inserted, &$skipped) {
        $existing = Employee::withoutGlobalScopes()->where('employee_code', $d['employee_code'])->first();
        if ($existing) { $skipped++; return $existing; }
        $inserted++;
        return Employee::create(array_merge([
            'company_id' => $cid,
            'department_id' => $dept->get('Human Resources', $dept->first()),
            'job_id' => $jobs->first(),
            'branch_id' => $br->get('Head Office', $br->first()),
            'employment_type_id' => $types->get('Full Time', $types->first()),
            'status_id' => $st->get('Active', $st->first()),
            'leave_entitlement_category' => 'normal',
        ], $d));
    };

    $ahmed = $mk(['employee_code'=>'D001','first_name'=>'Ahmed','last_name'=>'Mahmoud','full_name'=>'Ahmed Mahmoud','national_id'=>'29201010101010','phone'=>'01000000001','email'=>'ahmed.demo@masar.test','gender'=>'male','birth_date'=>'1992-01-01','address'=>'Nasr City, Cairo','marital_status'=>'married','qualification'=>'B.Com','hire_date'=>'2021-02-01','contract_start_date'=>'2021-02-01','contract_end_date'=>'2027-01-31','shift_start'=>'09:00','shift_end'=>'17:00','manual_annual_balance'=>12,'gross_salary'=>$grossOf(18000)]);
    $mona  = $mk(['employee_code'=>'D002','first_name'=>'Mona','last_name'=>'Ali','full_name'=>'Mona Ali','national_id'=>'29505050505050','phone'=>'01000000002','email'=>'mona.demo@masar.test','gender'=>'female','birth_date'=>'1995-05-05','address'=>'New Cairo','marital_status'=>'single','qualification'=>'BA Media','hire_date'=>'2023-06-01','contract_start_date'=>'2023-06-01','contract_end_date'=>'2026-05-31','shift_start'=>'10:00','shift_end'=>'18:00','manager_employee_id'=>$ahmed->id,'gross_salary'=>$grossOf(12000)]);
    $sara  = $mk(['employee_code'=>'D003','first_name'=>'Sara','last_name'=>'Hassan','full_name'=>'Sara Hassan','national_id'=>'29003030303030','phone'=>'01000000003','email'=>'sara.demo@masar.test','gender'=>'female','birth_date'=>'1990-03-03','address'=>'Sheikh Zayed','marital_status'=>'married','qualification'=>'BSc CS','hire_date'=>'2019-09-01','contract_start_date'=>'2019-09-01','contract_end_date'=>null,'shift_start'=>'09:00','shift_end'=>'17:00','expected_delivery_date'=>'2027-01-15','pregnancy_reduction_start'=>'2026-12-01','gross_salary'=>$grossOf(25000)]);
    $omar  = $mk(['employee_code'=>'D004','first_name'=>'Omar','last_name'=>'Farouk','full_name'=>'Omar Farouk','national_id'=>'28807070707070','phone'=>'01000000004','email'=>'omar.demo@masar.test','gender'=>'male','birth_date'=>'1988-07-07','address'=>'6th October','marital_status'=>'married','qualification'=>'MBA','hire_date'=>'2024-01-15','contract_start_date'=>'2024-01-15','contract_end_date'=>'2026-01-14','shift_start'=>'08:30','shift_end'=>'16:30','gross_salary'=>$grossOf(22000)]);
    $hassan= $mk(['employee_code'=>'D005','first_name'=>'Hassan','last_name'=>'Mostafa','full_name'=>'Hassan Mostafa','national_id'=>'29404040404040','phone'=>'01000000005','email'=>'hassan.demo@masar.test','gender'=>'male','birth_date'=>'1994-04-04','address'=>'Heliopolis, Cairo','marital_status'=>'single','qualification'=>'BSc Engineering','hire_date'=>'2022-03-01','contract_start_date'=>'2022-03-01','contract_end_date'=>'2027-02-28','shift_start'=>'09:00','shift_end'=>'17:00','gross_salary'=>$grossOf(15000)]);
    $laila = $mk(['employee_code'=>'D006','first_name'=>'Laila','last_name'=>'Samir','full_name'=>'Laila Samir','national_id'=>'29606060606060','phone'=>'01000000006','email'=>'laila.demo@masar.test','gender'=>'female','birth_date'=>'1996-06-06','address'=>'Maadi, Cairo','marital_status'=>'single','qualification'=>'BA Accounting','hire_date'=>'2024-05-01','contract_start_date'=>'2024-05-01','contract_end_date'=>'2027-04-30','shift_start'=>'09:00','shift_end'=>'17:00','gross_salary'=>$grossOf(13500)]);
    $karim = $mk(['employee_code'=>'D007','first_name'=>'Karim','last_name'=>'Adel','full_name'=>'Karim Adel','national_id'=>'29109090909090','phone'=>'01000000007','email'=>'karim.demo@masar.test','gender'=>'male','birth_date'=>'1991-09-09','address'=>'Dokki, Giza','marital_status'=>'married','qualification'=>'B.Com','hire_date'=>'2020-11-01','contract_start_date'=>'2020-11-01','contract_end_date'=>null,'shift_start'=>'08:00','shift_end'=>'16:00','gross_salary'=>$grossOf(17000)]);

    // --- Salaries, profiles, contacts, bank accounts ------------------------------
    // NOTE: these child tables carry a company scope, so company_id is set
    // explicitly (direct inserts bypass the model hook that would do it).
    foreach ([[$ahmed,18000], [$mona,12000], [$sara,25000], [$omar,22000], [$hassan,15000], [$laila,13500], [$karim,17000]] as [$e, $amt]) {
        $structure = $calc->structure(['salary_type' => 'net', 'salary_input' => $amt]);
        $ensure('salaries', ['employee_id' => $e->id, 'effective_from' => $e->hire_date],
            array_merge(['company_id'=>$cid,'employee_id'=>$e->id,'effective_from'=>$e->hire_date,'salary_type'=>'net','salary_input'=>$amt,'deductions'=>0,'fixed_deductions'=>0,'status'=>'Active','payment_method_id'=>$pm->first(),'created_at'=>$now,'updated_at'=>$now], $calc->persistFields($structure)));
        // Keep the denormalized gross on the employee row in sync (the UI reads it).
        if (!$e->gross_salary && isset($structure['gross'])) {
            $e->timestamps = false;
            $e->update(['gross_salary' => $structure['gross']]);
        }
        $ensure('employee_profile_details', ['employee_id' => $e->id],
            ['company_id'=>$cid,'employee_id'=>$e->id,'nationality'=>'Egyptian','personal_email'=>$e->email,'work_pattern'=>'Full-time office','weekly_work_days'=>'Sunday-Thursday','daily_work_hours'=>8,'contract_type'=>'Fixed term','probation_period'=>'3 months','annual_leave_days'=>21,'contract_attachment_status'=>'Attached','currency'=>'EGP','military_status'=>$e->gender==='male'?'Completed':'Not applicable','created_at'=>$now,'updated_at'=>$now]);
        $ensure('employee_emergency_contacts', ['employee_id' => $e->id, 'name' => 'Demo Contact '.$e->first_name],
            ['company_id'=>$cid,'employee_id'=>$e->id,'name'=>'Demo Contact '.$e->first_name,'phone'=>'0101000000'.$e->id,'relationship'=>'Family','is_primary'=>true,'created_at'=>$now,'updated_at'=>$now]);
        $ensure('employee_bank_accounts', ['employee_id' => $e->id, 'account_number' => 'ACC'.str_pad($e->id,8,'0',STR_PAD_LEFT)],
            ['company_id'=>$cid,'employee_id'=>$e->id,'bank_id'=>$bank->first(),'account_holder_name'=>$e->full_name,'account_number'=>'ACC'.str_pad($e->id,8,'0',STR_PAD_LEFT),'iban'=>'EG380000000000000'.str_pad($e->id,6,'0',STR_PAD_LEFT),'payment_method_id'=>$pm->first(),'is_primary'=>true,'effective_from'=>$e->hire_date,'status'=>'Active','created_at'=>$now,'updated_at'=>$now]);
    }

    // --- Attendance ----------------------------------------------------------------
    foreach ([[$ahmed,'2026-11-02','09:05','17:12','Present',0,7], [$mona,'2026-11-03','10:21','18:00','Late',21,0], [$sara,'2026-11-04','09:00','16:10','Early Leave',0,0], [$omar,'2026-11-05',null,null,'Absent',0,0], [$hassan,'2026-11-06','08:55','17:02','Present',0,0], [$laila,'2026-11-06','09:18','17:05','Late',18,0], [$karim,'2026-11-06','08:02','16:20','Present',0,20]] as [$e,$date,$in,$out,$status,$late,$ot]) {
        $ensure('attendance', ['employee_id' => $e->id, 'attendance_date' => $date],
            ['company_id'=>$cid,'employee_id'=>$e->id,'attendance_date'=>$date,'check_in'=>$in,'check_out'=>$out,'status'=>$status,'source'=>'manual','approval_status'=>'approved','late_minutes'=>$late,'overtime_minutes'=>$ot,'early_leave_minutes'=>$status==='Early Leave'?50:0,'worked_hours'=>$in&&$out?7.5:0,'created_at'=>$now,'updated_at'=>$now]);
    }

    // --- Leaves + balances ----------------------------------------------------------
    foreach ([[$mona,'casual','2026-10-20','2026-10-20',1,'Approved'], [$sara,'maternity','2026-12-01','2027-03-31',120,'Pending'], [$ahmed,'annual','2026-09-10','2026-09-12',3,'Approved']] as [$e,$code,$from,$to,$days,$status]) {
        $ensure('leaves', ['employee_id' => $e->id, 'start_date' => $from, 'leave_type_id' => $lt[$code] ?? null],
            ['company_id'=>$cid,'employee_id'=>$e->id,'leave_type_id'=>$lt[$code]??null,'start_date'=>$from,'end_date'=>$to,'days'=>$days,'reason'=>'Demo '.$code,'status'=>$status,'created_at'=>$now,'updated_at'=>$now]);
    }
    foreach ([$ahmed,$mona,$sara,$omar,$hassan,$laila,$karim] as $e) {
        $ensure('leave_balances', ['employee_id' => $e->id, 'leave_year' => 2026],
            ['employee_id'=>$e->id,'leave_year'=>2026,'annual_entitlement'=>21,'annual_used'=>$e->id===$ahmed->id?3:0,'carried_balance'=>0,'annual_balance_remaining'=>$e->id===$ahmed->id?18:21,'casual_used'=>$e->id===$mona->id?1:0,'advance_used'=>0,'manual_adjustment'=>0,'created_at'=>$now,'updated_at'=>$now]);
    }

    // --- HR requests -----------------------------------------------------------------
    foreach ([[$ahmed,'mission','مهمة عمل','زيارة عميل','Pending'], [$omar,'permission','إذن انصراف','ظرف شخصي','Approved']] as [$e,$type,$title,$details,$status]) {
        $ensure('hr_requests', ['employee_id' => $e->id, 'title' => $title],
            ['company_id'=>$cid,'employee_id'=>$e->id,'request_type'=>$type,'title'=>$title,'details'=>$details,'status'=>$status,'created_at'=>$now,'updated_at'=>$now]);
    }

    // --- Documents (with real downloadable demo PDF files) ----------------------------
    // Ahmed (D001) receives every *required* document type so one demo profile
    // shows a 100% completion panel; Mona keeps a single document so the
    // incomplete state is also visible.
    $requiredTypes = DB::table('document_types')->where('is_required', true)->where('is_active', true)->orderBy('id')->get();
    $docPlan = [];
    foreach ($requiredTypes as $t) $docPlan[] = [$ahmed, $t->id, $t->name];
    $natIdType = DB::table('document_types')->where('name', 'بطاقة الرقم القومي')->first() ?? $requiredTypes->first();
    if ($natIdType) $docPlan[] = [$mona, $natIdType->id, $natIdType->name];

    foreach ($docPlan as [$e, $typeId, $typeName]) {
        $relPath = "documents/{$e->id}/demo-{$e->id}-{$typeId}.pdf";
        $absPath = storage_path('app/'.$relPath);
        if (!is_file($absPath)) {
            @mkdir(dirname($absPath), 0775, true);
            file_put_contents($absPath, $makePdf($typeName.' - '.$e->full_name));
        }
        $ensure('documents', ['employee_id' => $e->id, 'document_type_id' => $typeId],
            ['company_id'=>$cid,'employee_id'=>$e->id,'document_type_id'=>$typeId,'document_name'=>$typeName,'document_type'=>'PDF','file_name'=>basename($relPath),'file_path'=>$relPath,'mime_type'=>'application/pdf','file_size'=>@filesize($absPath) ?: 1024,'status'=>'Valid','issue_date'=>'2020-01-01','expiry_date'=>'2030-01-01','uploaded_at'=>$now,'created_at'=>$now,'updated_at'=>$now]);
    }

    // --- Insurance, penalty, movement -------------------------------------------------
    $ensure('insurance_records', ['employee_id' => $sara->id, 'insurance_number' => 'INS-D003'],
        ['company_id'=>$cid,'employee_id'=>$sara->id,'insurance_number'=>'INS-D003','job_code'=>'243105','insurance_office'=>'Cairo First','insurance_category'=>'Regular','insurance_start_date'=>'2019-09-01','registration_deadline'=>'2019-10-01','insured_salary'=>25000,'status'=>'Active','created_at'=>$now,'updated_at'=>$now]);
    $ensure('penalties', ['employee_id' => $mona->id, 'title' => 'Late arrival'],
        ['company_id'=>$cid,'employee_id'=>$mona->id,'penalty_type_id'=>$pt->get('Written Warning', $pt->first()),'penalty_date'=>'2026-11-03','title'=>'Late arrival','reason'=>'Repeated lateness','action'=>'Written warning','amount'=>0,'deduction_days'=>0,'status'=>'Open','created_at'=>$now,'updated_at'=>$now]);
    $ensure('movement_records', ['employee_id' => $ahmed->id, 'movement_type_id' => $mt->get('Promotion', $mt->first()), 'movement_date' => '2025-01-01'],
        ['company_id'=>$cid,'employee_id'=>$ahmed->id,'movement_type_id'=>$mt->get('Promotion', $mt->first()),'movement_date'=>'2025-01-01','old_department_id'=>$ahmed->department_id,'new_department_id'=>$ahmed->department_id,'old_job_id'=>$ahmed->job_id,'new_job_id'=>$ahmed->job_id,'old_salary'=>15000,'new_salary'=>18000,'reason'=>'Annual promotion','created_at'=>$now,'updated_at'=>$now]);

    // --- Payroll runs in three states ---------------------------------------------------
    // Locked 2026-08, Approved 2026-09, Draft 2026-10 - so the payroll screens,
    // payslips and reports all have data in every workflow state.
    // One run per company+month is enforced by a unique key; an existing run
    // whose status differs from the plan is left untouched (never downgraded).
    $allEmployees = [$ahmed,$mona,$sara,$omar,$hassan,$laila,$karim];
    foreach ([['2026-08-01','2026-08-31','Locked'], ['2026-09-01','2026-09-30','Approved'], ['2026-10-01','2026-10-31','Draft']] as [$mStart,$mEnd,$runStatus]) {
        $run = DB::table('payroll_runs')->where(['company_id'=>$cid,'payroll_month'=>$mStart])->first();
        if (!$run) {
            $runId = DB::table('payroll_runs')->insertGetId(['company_id'=>$cid,'payroll_month'=>$mStart,'period_start'=>$mStart,'period_end'=>$mEnd,'status'=>$runStatus,'created_at'=>$now,'updated_at'=>$now]);
            $inserted++;
        } else {
            $runId = $run->id;
            $skipped++;
            if ($run->status !== $runStatus) { echo "note: {$mStart} payroll run #{$runId} already exists with status {$run->status}; leaving it untouched.\n"; continue; }
        }
        foreach ($allEmployees as $e) {
            $sal = DB::table('salaries')->where('employee_id', $e->id)->where('status', 'Active')->first();
            if (!$sal) continue;
            $ensure('payroll_records', ['payroll_run_id' => $runId, 'employee_id' => $e->id],
                ['company_id'=>$cid,'payroll_run_id'=>$runId,'employee_id'=>$e->id,'salary_id'=>$sal->id,'agreed_net_salary'=>$sal->net_salary,'payroll_month'=>$mStart,'basic_salary'=>$sal->basic_salary,'allowances'=>$sal->allowances,'housing_allowance'=>$sal->housing_allowance,'transportation_allowance'=>$sal->transportation_allowance,'meal_allowance'=>$sal->meal_allowance,'deductions'=>$sal->deductions,'net_salary'=>$sal->net_salary,'status'=>$runStatus,'is_locked'=>$runStatus==='Locked'?1:0,'gross_salary'=>$sal->gross_salary,'monthly_total'=>$sal->gross_salary,'insurance_wage'=>$sal->insurance_wage?:0,'employee_insurance'=>$sal->employee_insurance,'income_tax'=>$sal->income_tax,'martyrs_fund'=>$sal->martyrs_fund,'employer_insurance'=>$sal->employer_insurance,'employee_cost'=>$sal->employee_cost,'fixed_deductions'=>$sal->fixed_deductions,'clothing_allowance'=>$sal->clothing_allowance,'created_at'=>$now,'updated_at'=>$now]);
        }
    }

    // --- Draft end-of-service settlement -------------------------------------------------
    $ensure('settlements', ['employee_id' => $omar->id, 'last_working_day' => '2026-11-15'],
        ['company_id'=>$cid,'employee_id'=>$omar->id,'reason'=>'contract_end','last_working_day'=>'2026-11-15','status'=>'Draft','anchor_month'=>'2026-11-01','period_start'=>'2026-11-01','period_end'=>'2026-11-30','leave_balance_days'=>0,'leave_payout_mode'=>'pending','leave_payout_amount'=>0,'advance_outstanding'=>0,'advance_recovery_mode'=>'pending','advance_recovery_amount'=>0,'gratuity_amount'=>0,'manual_earnings'=>0,'manual_deductions'=>0,'total_earnings'=>0,'total_deductions'=>0,'net_amount'=>0,'notes'=>'Demo settlement (isolated test data)','created_at'=>$now,'updated_at'=>$now]);

    echo "employees: D001={$ahmed->id} D002={$mona->id} D003={$sara->id} D004={$omar->id} D005={$hassan->id} D006={$laila->id} D007={$karim->id}\n";
});

echo "Done. Rows inserted: {$inserted}, already present (skipped): {$skipped}.\n";
echo "Re-running this script makes no further changes once the dataset is complete.\n";
