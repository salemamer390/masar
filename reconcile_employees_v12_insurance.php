<?php
declare(strict_types=1);

/*
 * Tazamun V12 - Insurance + Address Reconciliation
 * Updates addresses + S1 codes/records only.
 * Does NOT modify salaries/payroll or employee IDs/codes.
 */

$dsn = 'mysql:host=127.0.0.1;port=3306;dbname=tazamun_hr;charset=utf8mb4';
$user = 'root';
$pass = '';

$employees = [
    '101' => ['address' => 'شارع عبد المنعم الغربي – أول المنيا – المنيا', 's1_code' => '242302', 's1_title' => 'أخصائي شئون أفراد (شئون عاملين)'],
    '102' => ['address' => '2 عرب الحصن – عزبة عاطف – المطرية – القاهرة', 's1_code' => '911203', 's1_title' => 'عامل النظافة في المكتب'],
    '103' => ['address' => '24 ش علي باشا شعراوي – ش ولي العهد – حدائق القبة – القاهرة', 's1_code' => '264101', 's1_title' => 'كاتب مؤلفات عام (مؤلف)'],
    '104' => ['address' => '10 ش الصديق – الزيتون – القاهرة', 's1_code' => '216601', 's1_title' => 'مصمم رسوم (جرافيك)'],
    '105' => ['address' => '46 ش المدينة المنورة – حدائق القبة – القاهرة', 's1_code' => '243105', 's1_title' => 'أخصائي تسويق'],
    '106' => ['address' => 'عزبة البوصة – مركز أبو النمرس – الجيزة', 's1_code' => '243105', 's1_title' => 'أخصائي تسويق'],
    '107' => ['address' => 'الغزالي مركز فاقوس – الشرقية', 's1_code' => '216601', 's1_title' => 'مصمم رسوم (جرافيك)'],
];

try {
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $pdo->beginTransaction();

    echo "Tazamun V12 - Insurance + Address Reconciliation\n";
    echo "SAFE UPDATE | salary/payroll tables will NOT be touched\n\n";

    $checkCode = $pdo->prepare("SELECT id FROM insurance_job_codes WHERE code = :code LIMIT 1");
    $insertCode = $pdo->prepare("
        INSERT INTO insurance_job_codes (code, title, is_active, created_at, updated_at)
        VALUES (:code, :title, 1, NOW(), NOW())
    ");

    foreach ($employees as $data) {
        $checkCode->execute([':code' => $data['s1_code']]);
        if (!$checkCode->fetch()) {
            $insertCode->execute([
                ':code' => $data['s1_code'],
                ':title' => $data['s1_title'],
            ]);
            echo "[S1 ADDED] {$data['s1_code']} - {$data['s1_title']}\n";
        }
    }

    $getEmployee = $pdo->prepare("
        SELECT id, employee_code, full_name, address
        FROM employees
        WHERE employee_code = :employee_code
        LIMIT 1
    ");
    $updateAddress = $pdo->prepare("
        UPDATE employees SET address = :address, updated_at = NOW()
        WHERE id = :id
    ");

    $getCode = $pdo->prepare("
        SELECT id FROM insurance_job_codes WHERE code = :code LIMIT 1
    ");

    $getInsurance = $pdo->prepare("
        SELECT id FROM insurance_records
        WHERE employee_id = :employee_id
        ORDER BY id ASC LIMIT 1
    ");

    $updateInsurance = $pdo->prepare("
        UPDATE insurance_records
        SET job_code = :job_code,
            insurance_job_code_id = :insurance_job_code_id,
            insurance_status = COALESCE(insurance_status, 'مسجل'),
            status = 'Active',
            updated_at = NOW()
        WHERE id = :id
    ");

    $insertInsurance = $pdo->prepare("
        INSERT INTO insurance_records
        (employee_id, job_code, insurance_job_code_id, status, insurance_status, created_at, updated_at)
        VALUES (:employee_id, :job_code, :insurance_job_code_id, 'Active', 'مسجل', NOW(), NOW())
    ");

    foreach ($employees as $employeeCode => $data) {
        $getEmployee->execute([':employee_code' => $employeeCode]);
        $employee = $getEmployee->fetch();

        if (!$employee) {
            throw new RuntimeException("Employee code {$employeeCode} not found.");
        }

        $getCode->execute([':code' => $data['s1_code']]);
        $codeRow = $getCode->fetch();

        if (!$codeRow) {
            throw new RuntimeException("S1 code {$data['s1_code']} not found.");
        }

        if ((string)($employee['address'] ?? '') !== $data['address']) {
            $updateAddress->execute([
                ':address' => $data['address'],
                ':id' => $employee['id'],
            ]);
            echo "[ADDRESS UPDATED] {$employeeCode} - {$employee['full_name']}\n";
        } else {
            echo "[ADDRESS OK] {$employeeCode} - {$employee['full_name']}\n";
        }

        $getInsurance->execute([':employee_id' => $employee['id']]);
        $insurance = $getInsurance->fetch();

        if ($insurance) {
            $updateInsurance->execute([
                ':job_code' => $data['s1_code'],
                ':insurance_job_code_id' => $codeRow['id'],
                ':id' => $insurance['id'],
            ]);
            echo "[INSURANCE UPDATED] {$employeeCode} -> {$data['s1_code']}\n";
        } else {
            $insertInsurance->execute([
                ':employee_id' => $employee['id'],
                ':job_code' => $data['s1_code'],
                ':insurance_job_code_id' => $codeRow['id'],
            ]);
            echo "[INSURANCE CREATED] {$employeeCode} -> {$data['s1_code']}\n";
        }
    }

    $pdo->commit();

    echo "\nSUCCESS\n";
    echo "Transaction committed successfully.\n";
    echo "Salary/payroll data was NOT modified.\n";

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\nERROR - TRANSACTION ROLLED BACK\n";
    echo $e->getMessage() . "\n";
    exit(1);
}
