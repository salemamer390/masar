<?php
declare(strict_types=1);

/*
 * Tazamun V12 - Employee Reconciliation
 * Updates employee master/profile data only.
 * NO INSERT / NO DELETE / NO salary changes.
 *
 * Run:
 * php reconcile_employees_v12.php
 */

function envFile(string $path): array {
    if (!is_file($path)) throw new RuntimeException(".env not found: {$path}");
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $v = trim($v);
        if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
            $v = substr($v, 1, -1);
        }
        $out[trim($k)] = $v;
    }
    return $out;
}

function lookup(PDO $pdo, string $table, string $column, string $value): ?int {
    $allowed = [
        'departments' => 'name',
        'jobs' => 'title',
        'branches' => 'name',
        'employment_types' => 'name',
        'employee_statuses' => 'name',
    ];
    if (!isset($allowed[$table]) || $allowed[$table] !== $column) {
        throw new InvalidArgumentException("Invalid lookup {$table}.{$column}");
    }
    $s = $pdo->prepare("SELECT id FROM `{$table}` WHERE `{$column}` = :v LIMIT 1");
    $s->execute(['v' => $value]);
    $id = $s->fetchColumn();
    return $id === false ? null : (int)$id;
}

function managerId(PDO $pdo, string $name): ?int {
    $s = $pdo->prepare("SELECT id FROM employees WHERE full_name = :n LIMIT 1");
    $s->execute(['n' => $name]);
    $id = $s->fetchColumn();
    return $id === false ? null : (int)$id;
}

function phone(string $v): string {
    $v = preg_replace('/\D+/', '', $v) ?? '';
    return (strlen($v) === 10 && $v[0] === '1') ? '0'.$v : $v;
}

function email(?string $v): ?string {
    if ($v === null) return null;
    $v = trim($v);
    return ($v === '' || $v === '-') ? null : $v;
}

/*
 * Source data. Salary fields are intentionally NOT included.
 * Amani and Mohamed dates are kept exactly as confirmed by the user.
 */
$E = [
'101'=>['زياد محمود فرغلي','29904260101078','01045710481','zeyad.m.farghaly99@gmail.com','تسويق','Content Creator','2026-08-16','2026-08-16','2027-08-15','حضوري',null],
'102'=>['سيف الدين مجدي','29903050100536','01115857752','seifmagdy1999@gmail.com','تسويق','Graphic Designer','2026-08-23','2026-08-23','2027-08-22','حضوري',null],
'103'=>['عبدالرحمن طارق احمد','30410170101918','01021628695','abdulrhman.ttarek20@gmail.com','تسويق','Marketing Specialist','2026-08-23','2026-08-23','2027-08-22','حضوري',null],
'104'=>['عامر سالم جمعه','29310172403555','01001028549','amer.salem2130@gmail.com','الموارد البشرية','HR','2026-07-29','2026-07-29','2027-07-28','حضوري',null],
'105'=>['نجية محمود السيد','27902071202548','01016074925',null,'النظافة','Office Girl','2026-08-23','2026-08-20','2027-08-19','حضوري',null],
'106'=>['اماني كرم علي قاسم','29703222700523','01067107176','Amanykaram54@gmail.com','تسويق','Media Buyer','2027-08-23','2026-08-23','2027-08-22','عن بعد','مباشرة العمل - اصل الاوراق'],
'107'=>['محمد صلاح عسكر','30009021301673','01011264098','mosalah168200@gmail.com','تسويق','Graphic Designer','2028-09-01','2026-09-01','2027-08-31','حضوري',null],
];

$env = envFile(__DIR__.'/.env');
$db = $env['DB_DATABASE'] ?? '';
if ($db !== 'tazamun_hr') throw new RuntimeException("DB_DATABASE must be tazamun_hr.");
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '3306';
$user = $env['DB_USERNAME'] ?? '';
$pass = $env['DB_PASSWORD'] ?? '';

$pdo = new PDO(
    "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
    $user, $pass,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
);

echo "Tazamun V12 - Employee Reconciliation\n";
echo "UPDATE ONLY | salaries table will NOT be touched\n\n";

$pdo->beginTransaction();

try {
    $find = $pdo->prepare("SELECT * FROM employees WHERE employee_code=:code LIMIT 1");

    $sql = "UPDATE employees SET
        first_name=:first_name, middle_name=:middle_name, last_name=:last_name,
        full_name=:full_name, nationality='مصري', national_id=:national_id,
        phone=:phone, email=:email, department_id=:department_id, job_id=:job_id,
        branch_id=:branch_id, employment_type_id=:employment_type_id, status_id=:status_id,
        hire_date=:hire_date, contract_start_date=:contract_start_date,
        contract_end_date=:contract_end_date, contract_duration='1 year',
        manager_employee_id=:manager_employee_id, direct_manager_name='طاهر احمد الجباحي',
        work_location='مصر', work_pattern=:work_pattern,
        weekly_work_days='الاحد الي الخميس', daily_work_hours=8,
        working_hours_text='10:00 AM - 6:00 PM', contract_type='Fixed Contract',
        probation_period='3 months', annual_leave_days=15,
        contract_attachment_status='مرفق', attached_documents=:attached_documents,
        additional_conditions=NULL, currency='EGP'
        WHERE id=:id";

    $update = $pdo->prepare($sql);
    $updated = 0; $unchanged = 0;

    foreach ($E as $code => $x) {
        [$name,$nid,$ph,$em,$dept,$job,$hire,$cs,$ce,$pattern,$docs] = $x;

        $find->execute(['code'=>$code]);
        $old = $find->fetch();
        if (!$old) throw new RuntimeException("Employee {$code} not found. Rollback.");

        $ids = [
            'department_id'=>lookup($pdo,'departments','name',$dept),
            'job_id'=>lookup($pdo,'jobs','title',$job),
            'branch_id'=>lookup($pdo,'branches','name','مصر'),
            'employment_type_id'=>lookup($pdo,'employment_types','name','Full Time'),
            'status_id'=>lookup($pdo,'employee_statuses','name','Active'),
        ];
        foreach ($ids as $k=>$v) {
            if ($v === null) throw new RuntimeException("Missing master-data {$k} for {$code}. Rollback.");
        }

        $mid = managerId($pdo,'طاهر احمد الجباحي');
        if ($mid === null) $mid = $old['manager_employee_id'];

        $parts = preg_split('/\s+/u', trim($name));
        $first = $parts[0] ?? $name;
        $last = count($parts)>1 ? array_pop($parts) : null;
        $middle = count($parts)>1 ? implode(' ', array_slice($parts,1)) : null;

        $new = [
            'first_name'=>$first, 'middle_name'=>$middle, 'last_name'=>$last,
            'full_name'=>$name, 'national_id'=>$nid, 'phone'=>phone($ph), 'email'=>email($em),
            'department_id'=>$ids['department_id'], 'job_id'=>$ids['job_id'],
            'branch_id'=>$ids['branch_id'], 'employment_type_id'=>$ids['employment_type_id'],
            'status_id'=>$ids['status_id'], 'hire_date'=>$hire,
            'contract_start_date'=>$cs, 'contract_end_date'=>$ce,
            'manager_employee_id'=>$mid, 'work_pattern'=>$pattern,
            'attached_documents'=>$docs, 'id'=>(int)$old['id']
        ];

        $changed = [];
        foreach ($new as $k=>$v) {
            if ($k==='id') continue;
            if ((string)($old[$k] ?? '') !== (string)($v ?? '')) $changed[]=$k;
        }

        if (!$changed) {
            echo "[UNCHANGED] {$code} - {$name}\n";
            $unchanged++;
            continue;
        }

        $update->execute($new);
        echo "[UPDATED]   {$code} - {$name}\n";
        echo "           ".implode(', ',$changed)."\n";
        $updated++;
    }

    $pdo->commit();

    echo "\n========================================\n";
    echo "Updated   : {$updated}\n";
    echo "Unchanged : {$unchanged}\n";
    echo "Errors    : 0\n";
    echo "Salary data: NOT MODIFIED\n";
    echo "========================================\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo "\nROLLBACK - ".$e->getMessage()."\n";
    exit(1);
}
?>
