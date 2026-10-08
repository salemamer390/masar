<?php

declare(strict_types=1);

/**
 * Tazamun HR - Employee Import Audit V12
 *
 * READ-ONLY AUDIT
 *
 * IMPORTANT:
 * This script DOES NOT:
 * - INSERT
 * - UPDATE
 * - DELETE
 * - CREATE TABLES
 * - ALTER TABLES
 * - MODIFY DATABASE DATA
 */

echo "============================================================\n";
echo " TAZAMUN HR - EMPLOYEE IMPORT AUDIT V12\n";
echo " READ-ONLY / NO DATABASE MODIFICATIONS\n";
echo "============================================================\n\n";

/*
|--------------------------------------------------------------------------
| Read .env
|--------------------------------------------------------------------------
*/

$projectRoot = __DIR__;
$envFile = $projectRoot . DIRECTORY_SEPARATOR . '.env';

if (!file_exists($envFile)) {
    exit("ERROR: .env file not found at:\n{$envFile}\n");
}

$env = [];

$lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {
    $line = trim($line);

    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }

    if (!str_contains($line, '=')) {
        continue;
    }

    [$key, $value] = explode('=', $line, 2);

    $key = trim($key);
    $value = trim($value);

    if (
        strlen($value) >= 2 &&
        (
            ($value[0] === '"' && $value[strlen($value) - 1] === '"') ||
            ($value[0] === "'" && $value[strlen($value) - 1] === "'")
        )
    ) {
        $value = substr($value, 1, -1);
    }

    $env[$key] = $value;
}

$dbHost = $env['DB_HOST'] ?? '127.0.0.1';
$dbPort = $env['DB_PORT'] ?? '3306';
$dbName = $env['DB_DATABASE'] ?? '';
$dbUser = $env['DB_USERNAME'] ?? '';
$dbPass = $env['DB_PASSWORD'] ?? '';

echo "[CONFIG]\n";
echo "Host     : {$dbHost}\n";
echo "Port     : {$dbPort}\n";
echo "Database : {$dbName}\n";
echo "Username : {$dbUser}\n";
echo "Password : " . ($dbPass !== '' ? '******' : '(empty)') . "\n\n";

/*
|--------------------------------------------------------------------------
| Connect
|--------------------------------------------------------------------------
*/

try {
    $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";

    $pdo = new PDO(
        $dsn,
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    echo "[OK] Database connection successful.\n\n";

} catch (Throwable $e) {

    echo "[ERROR] Database connection failed.\n";
    echo $e->getMessage() . "\n\n";

    echo "NO DATABASE CHANGES WERE MADE.\n";
    exit(1);
}

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function section(string $title): void
{
    echo "\n";
    echo "------------------------------------------------------------\n";
    echo " {$title}\n";
    echo "------------------------------------------------------------\n";
}

function tableExists(PDO $pdo, string $db, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = ?
          AND table_name = ?
    ");

    $stmt->execute([$db, $table]);

    return (int)$stmt->fetchColumn() > 0;
}

function getColumns(PDO $pdo, string $db, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT
            COLUMN_NAME,
            COLUMN_TYPE,
            IS_NULLABLE,
            COLUMN_DEFAULT,
            COLUMN_KEY,
            EXTRA
        FROM information_schema.columns
        WHERE table_schema = ?
          AND table_name = ?
        ORDER BY ORDINAL_POSITION
    ");

    $stmt->execute([$db, $table]);

    return $stmt->fetchAll();
}

function getForeignKeys(PDO $pdo, string $db, string $table): array
{
    $stmt = $pdo->prepare("
        SELECT
            COLUMN_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME,
            CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = ?
          AND REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY ORDINAL_POSITION
    ");

    $stmt->execute([$db, $table]);

    return $stmt->fetchAll();
}

function printRows(array $rows): void
{
    if (!$rows) {
        echo "No records found.\n";
        return;
    }

    foreach ($rows as $row) {
        echo json_encode(
            $row,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . "\n";
    }
}

/*
|--------------------------------------------------------------------------
| 1. Database Information
|--------------------------------------------------------------------------
*/

section('1. DATABASE INFORMATION');

$stmt = $pdo->query("
    SELECT
        DATABASE() AS database_name,
        VERSION() AS mysql_version
");

printRows($stmt->fetchAll());

/*
|--------------------------------------------------------------------------
| 2. Required Employee Related Tables
|--------------------------------------------------------------------------
*/

section('2. EMPLOYEE RELATED TABLES');

$requiredTables = [
    'employees',
    'departments',
    'jobs',
    'branches',
    'employment_types',
    'employee_statuses',
    'payment_methods',
    'salaries',
    'salary_history',
    'employee_allowances',
    'insurance_records',
    'employee_bank_accounts',
    'payroll_records',
    'payroll_earnings',
    'payroll_deductions',
    'overtime_records',
    'documents',
    'document_types',
    'movement_records',
];

foreach ($requiredTables as $table) {

    $exists = tableExists($pdo, $dbName, $table);

    echo sprintf(
        "%-30s : %s\n",
        $table,
        $exists ? 'EXISTS' : 'MISSING'
    );
}

/*
|--------------------------------------------------------------------------
| 3. Employees Table Structure
|--------------------------------------------------------------------------
*/

section('3. EMPLOYEES TABLE STRUCTURE');

if (tableExists($pdo, $dbName, 'employees')) {

    $columns = getColumns($pdo, $dbName, 'employees');

    foreach ($columns as $column) {

        echo sprintf(
            "%-32s | %-25s | NULL=%-3s | KEY=%-4s | DEFAULT=%s\n",
            $column['COLUMN_NAME'],
            $column['COLUMN_TYPE'],
            $column['IS_NULLABLE'],
            $column['COLUMN_KEY'],
            $column['COLUMN_DEFAULT'] ?? 'NULL'
        );
    }
}

/*
|--------------------------------------------------------------------------
| 4. Employee Foreign Keys
|--------------------------------------------------------------------------
*/

section('4. EMPLOYEE FOREIGN KEYS');

if (tableExists($pdo, $dbName, 'employees')) {

    $fks = getForeignKeys($pdo, $dbName, 'employees');

    if (!$fks) {
        echo "No foreign keys found.\n";
    } else {

        foreach ($fks as $fk) {

            echo sprintf(
                "%s -> %s.%s [%s]\n",
                $fk['COLUMN_NAME'],
                $fk['REFERENCED_TABLE_NAME'],
                $fk['REFERENCED_COLUMN_NAME'],
                $fk['CONSTRAINT_NAME']
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| 5. Current Employees
|--------------------------------------------------------------------------
*/

section('5. CURRENT EMPLOYEES');

if (tableExists($pdo, $dbName, 'employees')) {

    $stmt = $pdo->query("
        SELECT
            id,
            employee_code,
            full_name,
            nationality,
            national_id,
            phone,
            email,
            department_id,
            job_id,
            branch_id,
            employment_type_id,
            status_id,
            hire_date,
            contract_start_date,
            contract_end_date,
            work_location,
            work_pattern,
            weekly_work_days,
            daily_work_hours,
            currency
        FROM employees
        ORDER BY CAST(employee_code AS UNSIGNED), id
    ");

    $employees = $stmt->fetchAll();

    echo "Total employees: " . count($employees) . "\n\n";

    foreach ($employees as $employee) {

        echo json_encode(
            $employee,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ) . "\n";
    }
}

/*
|--------------------------------------------------------------------------
| 6. Duplicate Detection
|--------------------------------------------------------------------------
*/

section('6. DUPLICATE DETECTION');

if (tableExists($pdo, $dbName, 'employees')) {

    $checks = [
        'employee_code' => "
            SELECT employee_code, COUNT(*) AS total
            FROM employees
            WHERE employee_code IS NOT NULL
              AND employee_code <> ''
            GROUP BY employee_code
            HAVING COUNT(*) > 1
        ",

        'national_id' => "
            SELECT national_id, COUNT(*) AS total
            FROM employees
            WHERE national_id IS NOT NULL
              AND national_id <> ''
            GROUP BY national_id
            HAVING COUNT(*) > 1
        ",

        'email' => "
            SELECT email, COUNT(*) AS total
            FROM employees
            WHERE email IS NOT NULL
              AND email <> ''
            GROUP BY email
            HAVING COUNT(*) > 1
        ",
    ];

    foreach ($checks as $field => $sql) {

        echo "\nDuplicate {$field}:\n";

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            echo "NONE\n";
        } else {
            printRows($rows);
        }
    }
}

/*
|--------------------------------------------------------------------------
| 7. Master Data
|--------------------------------------------------------------------------
*/

$masterTables = [
    'departments',
    'jobs',
    'branches',
    'employment_types',
    'employee_statuses',
    'payment_methods',
    'document_types',
];

foreach ($masterTables as $table) {

    section("7. MASTER DATA - {$table}");

    if (!tableExists($pdo, $dbName, $table)) {
        echo "TABLE MISSING\n";
        continue;
    }

    $columns = getColumns($pdo, $dbName, $table);

    $columnNames = array_column($columns, 'COLUMN_NAME');

    $preferredOrder = [];

    foreach (['id', 'name', 'title', 'code', 'status', 'is_active'] as $field) {

        if (in_array($field, $columnNames, true)) {
            $preferredOrder[] = $field;
        }
    }

    if (!$preferredOrder) {
        echo "Columns:\n";
        foreach ($columnNames as $column) {
            echo "- {$column}\n";
        }
        continue;
    }

    $select = implode(', ', array_map(
        fn($column) => "`{$column}`",
        $preferredOrder
    ));

    $stmt = $pdo->query("
        SELECT {$select}
        FROM `{$table}`
        ORDER BY id
    ");

    printRows($stmt->fetchAll());
}

/*
|--------------------------------------------------------------------------
| 8. Required Master Data Mapping
|--------------------------------------------------------------------------
*/

section('8. REQUIRED MASTER DATA MAPPING');

$requiredMasterData = [

    'departments' => [
        'تسويق',
        'الموارد البشرية',
        'النظافة',
    ],

    'jobs' => [
        'Content Creator',
        'Graphic Designer',
        'Marketing Specialist',
        'HR',
        'Office Girl',
        'Media Buyer',
    ],

    'employment_types' => [
        'Full Time',
        'كامل',
        'Full-Time',
    ],
];

foreach ($requiredMasterData as $table => $values) {

    echo "\nTABLE: {$table}\n";

    if (!tableExists($pdo, $dbName, $table)) {
        echo "TABLE MISSING\n";
        continue;
    }

    $columns = getColumns($pdo, $dbName, $table);
    $columnNames = array_column($columns, 'COLUMN_NAME');

    $searchColumn = null;

    foreach (['name', 'title', 'label', 'type_name'] as $candidate) {

        if (in_array($candidate, $columnNames, true)) {
            $searchColumn = $candidate;
            break;
        }
    }

    if ($searchColumn === null) {
        echo "Could not determine master-data name column.\n";
        continue;
    }

    foreach ($values as $value) {

        $stmt = $pdo->prepare("
            SELECT *
            FROM `{$table}`
            WHERE `{$searchColumn}` = ?
            LIMIT 5
        ");

        $stmt->execute([$value]);

        $rows = $stmt->fetchAll();

        echo "\n{$value}:\n";

        if (!$rows) {
            echo "NOT FOUND\n";
        } else {
            printRows($rows);
        }
    }
}

/*
|--------------------------------------------------------------------------
| 9. Existing Employee Target Names
|--------------------------------------------------------------------------
*/

section('9. TARGET EMPLOYEE MATCHING');

$targetEmployees = [
    'زياد محمود فرغلي',
    'سيف الدين مجدي',
    'عبدالرحمن طارق احمد',
    'عامر سالم جمعه',
    'نجية محمود السيد',
    'اماني كرم علي قاسم',
    'محمد صلاح عسكر',
];

if (tableExists($pdo, $dbName, 'employees')) {

    foreach ($targetEmployees as $name) {

        $stmt = $pdo->prepare("
            SELECT
                id,
                employee_code,
                full_name,
                national_id,
                email,
                department_id,
                job_id,
                hire_date,
                contract_start_date,
                contract_end_date
            FROM employees
            WHERE full_name = ?
               OR full_name LIKE ?
            ORDER BY id
        ");

        $stmt->execute([
            $name,
            '%' . $name . '%',
        ]);

        $rows = $stmt->fetchAll();

        echo "\nTARGET: {$name}\n";

        if (!$rows) {
            echo "NO MATCH\n";
        } else {
            printRows($rows);
        }
    }
}

/*
|--------------------------------------------------------------------------
| 10. Salary Related Tables
|--------------------------------------------------------------------------
*/

$salaryTables = [
    'salaries',
    'salary_history',
    'employee_allowances',
    'payroll_records',
    'payroll_earnings',
    'payroll_deductions',
];

foreach ($salaryTables as $table) {

    section("10. SALARY TABLE - {$table}");

    if (!tableExists($pdo, $dbName, $table)) {
        echo "TABLE MISSING\n";
        continue;
    }

    $columns = getColumns($pdo, $dbName, $table);

    foreach ($columns as $column) {

        echo sprintf(
            "%-34s | %-25s | NULL=%-3s | KEY=%-4s\n",
            $column['COLUMN_NAME'],
            $column['COLUMN_TYPE'],
            $column['IS_NULLABLE'],
            $column['COLUMN_KEY']
        );
    }
}

/*
|--------------------------------------------------------------------------
| 11. Salary Data for Existing Employees
|--------------------------------------------------------------------------
*/

section('11. EXISTING SALARY DATA');

if (tableExists($pdo, $dbName, 'salaries')) {

    $count = (int)$pdo->query("SELECT COUNT(*) FROM salaries")->fetchColumn();

    echo "Salary records: {$count}\n";

    if ($count > 0) {

        $columns = getColumns($pdo, $dbName, 'salaries');
        $columnNames = array_column($columns, 'COLUMN_NAME');

        $wanted = [
            'id',
            'employee_id',
            'effective_from',
            'basic_salary',
            'allowances',
            'housing_allowance',
            'transportation_allowance',
            'meal_allowance',
            'clothing_allowance',
            'other_allowances',
            'net_salary',
            'gross_salary',
            'salary_type',
            'salary_input',
            'insurance_wage',
            'applied_insurance_wage',
            'employee_insurance',
            'income_tax',
            'annual_tax',
            'martyrs_fund',
            'total_deductions',
            'employer_insurance',
            'employee_cost',
            'status',
        ];

        $selectColumns = array_values(
            array_intersect($wanted, $columnNames)
        );

        if ($selectColumns) {

            $select = implode(', ', array_map(
                fn($column) => "`{$column}`",
                $selectColumns
            ));

            $stmt = $pdo->query("
                SELECT {$select}
                FROM salaries
                ORDER BY employee_id, effective_from, id
            ");

            printRows($stmt->fetchAll());
        }
    }
}

/*
|--------------------------------------------------------------------------
| 12. Employee Allowances
|--------------------------------------------------------------------------
*/

section('12. EMPLOYEE ALLOWANCES');

if (tableExists($pdo, $dbName, 'employee_allowances')) {

    $count = (int)$pdo
        ->query("SELECT COUNT(*) FROM employee_allowances")
        ->fetchColumn();

    echo "Employee allowance records: {$count}\n";

    if ($count > 0) {
        $stmt = $pdo->query("
            SELECT *
            FROM employee_allowances
            ORDER BY employee_id, id
        ");

        printRows($stmt->fetchAll());
    }
}

/*
|--------------------------------------------------------------------------
| 13. Insurance / Bank / Documents
|--------------------------------------------------------------------------
*/

$auditTables = [
    'insurance_records',
    'employee_bank_accounts',
    'documents',
];

foreach ($auditTables as $table) {

    section("13. {$table}");

    if (!tableExists($pdo, $dbName, $table)) {
        echo "TABLE MISSING\n";
        continue;
    }

    $count = (int)$pdo
        ->query("SELECT COUNT(*) FROM `{$table}`")
        ->fetchColumn();

    echo "Records: {$count}\n";

    $fks = getForeignKeys($pdo, $dbName, $table);

    echo "Foreign Keys:\n";

    if (!$fks) {
        echo "NONE\n";
    } else {

        foreach ($fks as $fk) {

            echo sprintf(
                "%s -> %s.%s\n",
                $fk['COLUMN_NAME'],
                $fk['REFERENCED_TABLE_NAME'],
                $fk['REFERENCED_COLUMN_NAME']
            );
        }
    }
}

/*
|--------------------------------------------------------------------------
| 14. Employee Table Constraints / Indexes
|--------------------------------------------------------------------------
*/

section('14. EMPLOYEE INDEXES / UNIQUE CONSTRAINTS');

if (tableExists($pdo, $dbName, 'employees')) {

    $stmt = $pdo->prepare("
        SELECT
            INDEX_NAME,
            NON_UNIQUE,
            COLUMN_NAME,
            SEQ_IN_INDEX
        FROM information_schema.statistics
        WHERE TABLE_SCHEMA = ?
          AND TABLE_NAME = ?
        ORDER BY INDEX_NAME, SEQ_IN_INDEX
    ");

    $stmt->execute([$dbName, 'employees']);

    printRows($stmt->fetchAll());
}

/*
|--------------------------------------------------------------------------
| 15. Date / Data Quality Audit
|--------------------------------------------------------------------------
*/

section('15. EMPLOYEE DATA QUALITY');

if (tableExists($pdo, $dbName, 'employees')) {

    $checks = [

        'Missing employee code' => "
            SELECT id, full_name
            FROM employees
            WHERE employee_code IS NULL
               OR employee_code = ''
        ",

        'Missing full name' => "
            SELECT id, employee_code
            FROM employees
            WHERE full_name IS NULL
               OR TRIM(full_name) = ''
        ",

        'Invalid contract dates' => "
            SELECT
                id,
                employee_code,
                full_name,
                contract_start_date,
                contract_end_date
            FROM employees
            WHERE contract_start_date IS NOT NULL
              AND contract_end_date IS NOT NULL
              AND contract_end_date < contract_start_date
        ",

        'Invalid hire date vs contract end' => "
            SELECT
                id,
                employee_code,
                full_name,
                hire_date,
                contract_end_date
            FROM employees
            WHERE hire_date IS NOT NULL
              AND contract_end_date IS NOT NULL
              AND contract_end_date < hire_date
        ",
    ];

    foreach ($checks as $title => $sql) {

        echo "\n{$title}:\n";

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            echo "NONE\n";
        } else {
            printRows($rows);
        }
    }
}

/*
|--------------------------------------------------------------------------
| 16. Target Data - Manual Validation Notes
|--------------------------------------------------------------------------
*/

section('16. TARGET DATA REVIEW FLAGS');

echo "The following target-data values require human confirmation before import:\n\n";

echo "1) أماني كرم علي قاسم\n";
echo "   Hire date: 23/08/2027\n";
echo "   Contract start: 23/08/2026\n\n";

echo "2) محمد صلاح عسكر\n";
echo "   Hire date: 01/09/2028\n";
echo "   Contract start: 01/09/2026\n";
echo "   Contract end: 30/08/2027\n\n";

echo "3) نجية محمود السيد\n";
echo "   Hire date: 23/08/2026\n";
echo "   Contract start: 20/08/2026\n";
echo "   This may be intentional; no automatic correction will be made.\n";

/*
|--------------------------------------------------------------------------
| 17. Final Safety Statement
|--------------------------------------------------------------------------
*/

section('17. AUDIT COMPLETE');

echo "READ-ONLY AUDIT COMPLETED.\n";
echo "NO INSERT operations were performed.\n";
echo "NO UPDATE operations were performed.\n";
echo "NO DELETE operations were performed.\n";
echo "NO CREATE TABLE operations were performed.\n";
echo "NO ALTER TABLE operations were performed.\n\n";

echo "Next step after reviewing this report:\n";
echo "Create a separate employee import script based strictly on the audit results.\n";

echo "\n============================================================\n";
echo " END OF AUDIT\n";
echo "============================================================\n";