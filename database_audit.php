<?php

/**
 * Tazamun HR - Database Audit Script
 *
 * PURPOSE:
 * Read-only audit of the entire HR database.
 *
 * IMPORTANT:
 * - DOES NOT MODIFY DATABASE
 * - DOES NOT INSERT / UPDATE / DELETE
 * - DOES NOT CREATE TABLES
 * - DOES NOT RUN MIGRATIONS
 *
 * Database:
 * Provided as the first command-line argument.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

$host = '127.0.0.1';
$port = 3306;
$db = $argv[1] ?? null;

if ($db === null || $db === '') {
    fwrite(STDERR, "Usage: php database_audit.php <database_name>\n" . "Example: php database_audit.php masar\n");
    exit(1);
}

if (!preg_match('/^[A-Za-z0-9_]+$/', $db)) {
    fwrite(STDERR, "Invalid database name. Use letters, numbers, and underscores only.\n");
    exit(1);
}
$user = 'root';
$pass = '';

function section(string $title): void
{
    echo "\n";
    echo str_repeat("=", 90) . "\n";
    echo $title . "\n";
    echo str_repeat("=", 90) . "\n";
}

function subSection(string $title): void
{
    echo "\n";
    echo str_repeat("-", 70) . "\n";
    echo $title . "\n";
    echo str_repeat("-", 70) . "\n";
}

function row(array $data): void
{
    echo implode(" | ", array_map(
        static fn($v) => $v === null ? 'NULL' : (string)$v,
        $data
    )) . PHP_EOL;
}

function safe(string $value): string
{
    return str_replace(
        ["\r", "\n", "\t"],
        [' ', ' ', ' '],
        $value
    );
}

try {

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    section("DATABASE AUDIT");

    echo "Database : {$db}\n";
    echo "Host     : {$host}\n";
    echo "Mode     : READ ONLY\n";
    echo "Time     : " . date('Y-m-d H:i:s') . "\n";

    /*
    |--------------------------------------------------------------------------
    | 1. Database information
    |--------------------------------------------------------------------------
    */

    section("1. DATABASE INFORMATION");

    $dbInfo = $pdo->query("
        SELECT
            DEFAULT_CHARACTER_SET_NAME,
            DEFAULT_COLLATION_NAME
        FROM information_schema.SCHEMATA
        WHERE SCHEMA_NAME = DATABASE()
    ")->fetch();

    row([
        "Charset",
        $dbInfo['DEFAULT_CHARACTER_SET_NAME'] ?? 'UNKNOWN'
    ]);

    row([
        "Collation",
        $dbInfo['DEFAULT_COLLATION_NAME'] ?? 'UNKNOWN'
    ]);

    /*
    |--------------------------------------------------------------------------
    | 2. Tables
    |--------------------------------------------------------------------------
    */

    section("2. TABLE INVENTORY");

    $tables = $pdo->query("
        SELECT
            TABLE_NAME,
            ENGINE,
            TABLE_ROWS,
            CREATE_TIME,
            UPDATE_TIME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME
    ")->fetchAll();

    echo "Total tables: " . count($tables) . "\n\n";

    row([
        "TABLE",
        "ENGINE",
        "ROWS",
        "CREATED",
        "UPDATED"
    ]);

    foreach ($tables as $table) {
        row([
            $table['TABLE_NAME'],
            $table['ENGINE'],
            $table['TABLE_ROWS'],
            $table['CREATE_TIME'],
            $table['UPDATE_TIME']
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Table structure
    |--------------------------------------------------------------------------
    */

    section("3. TABLE STRUCTURE");

    $columns = $pdo->query("
        SELECT
            TABLE_NAME,
            ORDINAL_POSITION,
            COLUMN_NAME,
            COLUMN_TYPE,
            IS_NULLABLE,
            COLUMN_DEFAULT,
            COLUMN_KEY,
            EXTRA
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME, ORDINAL_POSITION
    ")->fetchAll();

    $currentTable = null;

    foreach ($columns as $column) {

        if ($currentTable !== $column['TABLE_NAME']) {
            $currentTable = $column['TABLE_NAME'];

            subSection("TABLE: " . $currentTable);

            row([
                "#",
                "COLUMN",
                "TYPE",
                "NULL",
                "DEFAULT",
                "KEY",
                "EXTRA"
            ]);
        }

        row([
            $column['ORDINAL_POSITION'],
            $column['COLUMN_NAME'],
            $column['COLUMN_TYPE'],
            $column['IS_NULLABLE'],
            $column['COLUMN_DEFAULT'],
            $column['COLUMN_KEY'],
            $column['EXTRA']
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Primary / Unique / Indexes
    |--------------------------------------------------------------------------
    */

    section("4. INDEXES / UNIQUE / PRIMARY KEYS");

    $indexes = $pdo->query("
        SELECT
            TABLE_NAME,
            INDEX_NAME,
            NON_UNIQUE,
            SEQ_IN_INDEX,
            COLUMN_NAME,
            INDEX_TYPE
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
        ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX
    ")->fetchAll();

    $currentIndex = '';

    foreach ($indexes as $index) {

        $key = $index['TABLE_NAME'] . '.' . $index['INDEX_NAME'];

        if ($key !== $currentIndex) {
            $currentIndex = $key;

            echo "\n";
            row([
                "TABLE",
                "INDEX",
                $index['NON_UNIQUE'] == 0 ? "UNIQUE" : "INDEX",
                "TYPE"
            ]);

            row([
                $index['TABLE_NAME'],
                $index['INDEX_NAME'],
                $index['NON_UNIQUE'] == 0 ? "YES" : "NO",
                $index['INDEX_TYPE']
            ]);
        }

        echo "    - " . $index['COLUMN_NAME'] . "\n";
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Foreign Keys
    |--------------------------------------------------------------------------
    */

    section("5. FOREIGN KEY RELATIONSHIPS");

    $foreignKeys = $pdo->query("
        SELECT
            CONSTRAINT_NAME,
            TABLE_NAME,
            COLUMN_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE
            TABLE_SCHEMA = DATABASE()
            AND REFERENCED_TABLE_NAME IS NOT NULL
        ORDER BY TABLE_NAME, CONSTRAINT_NAME
    ")->fetchAll();

    if (!$foreignKeys) {
        echo "NO FOREIGN KEYS FOUND.\n";
    } else {

        row([
            "CONSTRAINT",
            "TABLE",
            "COLUMN",
            "REFERENCES",
            "REFERENCED COLUMN"
        ]);

        foreach ($foreignKeys as $fk) {
            row([
                $fk['CONSTRAINT_NAME'],
                $fk['TABLE_NAME'],
                $fk['COLUMN_NAME'],
                $fk['REFERENCED_TABLE_NAME'],
                $fk['REFERENCED_COLUMN_NAME']
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 6. Record counts
    |--------------------------------------------------------------------------
    */

    section("6. RECORD COUNTS");

    foreach ($tables as $table) {

        $tableName = $table['TABLE_NAME'];

        $stmt = $pdo->query(
            "SELECT COUNT(*) AS total FROM `{$tableName}`"
        );

        $count = $stmt->fetch()['total'];

        echo str_pad($tableName, 45) . " : " . $count . "\n";
    }

    /*
    |--------------------------------------------------------------------------
    | 7. Employee audit
    |--------------------------------------------------------------------------
    */

    section("7. EMPLOYEES AUDIT");

    $employeeTableExists = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'employees'
    ")->fetchColumn();

    if ($employeeTableExists) {

        $employeeTotal = $pdo
            ->query("SELECT COUNT(*) FROM employees")
            ->fetchColumn();

        echo "Employees total: {$employeeTotal}\n";

        subSection("Employee master data");

        $employees = $pdo->query("
            SELECT
                id,
                employee_code,
                full_name,
                department_id,
                job_id,
                branch_id,
                employment_type_id,
                status_id,
                manager_employee_id,
                direct_manager_name,
                hire_date,
                contract_start_date,
                contract_end_date,
                email,
                phone,
                national_id
            FROM employees
            ORDER BY employee_code
        ")->fetchAll();

        foreach ($employees as $employee) {

            echo "\n";
            echo "Employee {$employee['employee_code']} - "
                . safe($employee['full_name']) . "\n";

            row([
                "id",
                $employee['id'],
                "department_id",
                $employee['department_id'],
                "job_id",
                $employee['job_id'],
                "branch_id",
                $employee['branch_id']
            ]);

            row([
                "status_id",
                $employee['status_id'],
                "employment_type_id",
                $employee['employment_type_id'],
                "manager_employee_id",
                $employee['manager_employee_id']
            ]);

            row([
                "hire_date",
                $employee['hire_date'],
                "contract_start",
                $employee['contract_start_date'],
                "contract_end",
                $employee['contract_end_date']
            ]);
        }

        /*
        |--------------------------------------------------------------------------
        | Employee duplicate checks
        |--------------------------------------------------------------------------
        */

        subSection("Duplicate employee codes");

        $duplicates = $pdo->query("
            SELECT employee_code, COUNT(*) AS total
            FROM employees
            GROUP BY employee_code
            HAVING COUNT(*) > 1
        ")->fetchAll();

        if (!$duplicates) {
            echo "OK - No duplicate employee codes.\n";
        } else {
            foreach ($duplicates as $item) {
                row([
                    $item['employee_code'],
                    $item['total']
                ]);
            }
        }

        subSection("Duplicate National IDs");

        $duplicates = $pdo->query("
            SELECT national_id, COUNT(*) AS total
            FROM employees
            WHERE national_id IS NOT NULL
            AND national_id <> ''
            GROUP BY national_id
            HAVING COUNT(*) > 1
        ")->fetchAll();

        if (!$duplicates) {
            echo "OK - No duplicate national IDs.\n";
        } else {
            foreach ($duplicates as $item) {
                row([
                    $item['national_id'],
                    $item['total']
                ]);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Employee broken references
        |--------------------------------------------------------------------------
        */

        subSection("Employees with missing department");

        $missing = $pdo->query("
            SELECT e.employee_code, e.full_name, e.department_id
            FROM employees e
            LEFT JOIN departments d
                ON d.id = e.department_id
            WHERE e.department_id IS NOT NULL
            AND d.id IS NULL
        ")->fetchAll();

        if (!$missing) {
            echo "OK\n";
        } else {
            foreach ($missing as $item) {
                row([
                    $item['employee_code'],
                    $item['full_name'],
                    $item['department_id']
                ]);
            }
        }

        subSection("Employees with missing job");

        $missing = $pdo->query("
            SELECT e.employee_code, e.full_name, e.job_id
            FROM employees e
            LEFT JOIN jobs j
                ON j.id = e.job_id
            WHERE e.job_id IS NOT NULL
            AND j.id IS NULL
        ")->fetchAll();

        if (!$missing) {
            echo "OK\n";
        } else {
            foreach ($missing as $item) {
                row([
                    $item['employee_code'],
                    $item['full_name'],
                    $item['job_id']
                ]);
            }
        }

        subSection("Employees with missing status");

        $missing = $pdo->query("
            SELECT e.employee_code, e.full_name, e.status_id
            FROM employees e
            LEFT JOIN employee_statuses s
                ON s.id = e.status_id
            WHERE e.status_id IS NOT NULL
            AND s.id IS NULL
        ")->fetchAll();

        if (!$missing) {
            echo "OK\n";
        } else {
            foreach ($missing as $item) {
                row([
                    $item['employee_code'],
                    $item['full_name'],
                    $item['status_id']
                ]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 8. HR Requests
    |--------------------------------------------------------------------------
    */

    section("8. HR REQUESTS AUDIT");

    $hrRequestsExists = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'hr_requests'
    ")->fetchColumn();

    if ($hrRequestsExists) {

        $total = $pdo
            ->query("SELECT COUNT(*) FROM hr_requests")
            ->fetchColumn();

        echo "Total HR Requests: {$total}\n";

        subSection("Requests by type");

        $rows = $pdo->query("
            SELECT
                request_type,
                COUNT(*) AS total
            FROM hr_requests
            GROUP BY request_type
            ORDER BY total DESC
        ")->fetchAll();

        if (!$rows) {
            echo "No HR requests yet.\n";
        } else {
            foreach ($rows as $item) {
                row([
                    $item['request_type'],
                    $item['total']
                ]);
            }
        }

        subSection("Requests by status");

        $rows = $pdo->query("
            SELECT
                status,
                COUNT(*) AS total
            FROM hr_requests
            GROUP BY status
            ORDER BY total DESC
        ")->fetchAll();

        if (!$rows) {
            echo "No statuses found.\n";
        } else {
            foreach ($rows as $item) {
                row([
                    $item['status'],
                    $item['total']
                ]);
            }
        }

        subSection("Requests with invalid employee");

        $rows = $pdo->query("
            SELECT
                r.id,
                r.employee_id
            FROM hr_requests r
            LEFT JOIN employees e
                ON e.id = r.employee_id
            WHERE e.id IS NULL
        ")->fetchAll();

        if (!$rows) {
            echo "OK\n";
        } else {
            foreach ($rows as $item) {
                row([
                    "request_id",
                    $item['id'],
                    "employee_id",
                    $item['employee_id']
                ]);
            }
        }
    } else {
        echo "hr_requests table does not exist.\n";
    }

    /*
    |--------------------------------------------------------------------------
    | 9. Leaves
    |--------------------------------------------------------------------------
    */

    section("9. LEAVES AUDIT");

    $leavesExists = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'leaves'
    ")->fetchColumn();

    if ($leavesExists) {

        $total = $pdo
            ->query("SELECT COUNT(*) FROM leaves")
            ->fetchColumn();

        echo "Total leaves: {$total}\n";

        subSection("Leaves by status");

        $rows = $pdo->query("
            SELECT
                status,
                COUNT(*) AS total
            FROM leaves
            GROUP BY status
            ORDER BY total DESC
        ")->fetchAll();

        foreach ($rows as $item) {
            row([
                $item['status'],
                $item['total']
            ]);
        }

        subSection("Leaves with invalid employees");

        $rows = $pdo->query("
            SELECT
                l.id,
                l.employee_id
            FROM leaves l
            LEFT JOIN employees e
                ON e.id = l.employee_id
            WHERE e.id IS NULL
        ")->fetchAll();

        if (!$rows) {
            echo "OK\n";
        } else {
            foreach ($rows as $item) {
                row([
                    "leave_id",
                    $item['id'],
                    "employee_id",
                    $item['employee_id']
                ]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 10. Insurance
    |--------------------------------------------------------------------------
    */

    section("10. INSURANCE AUDIT");

    $insuranceExists = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'insurance_records'
    ")->fetchColumn();

    if ($insuranceExists) {

        $total = $pdo
            ->query("SELECT COUNT(*) FROM insurance_records")
            ->fetchColumn();

        echo "Insurance records: {$total}\n";

        subSection("Insurance records");

        $rows = $pdo->query("
            SELECT
                ir.id,
                ir.employee_id,
                e.employee_code,
                e.full_name,
                ir.job_code,
                ir.insurance_job_code_id,
                ir.insurance_number,
                ir.insured_salary,
                ir.status,
                ir.insurance_status
            FROM insurance_records ir
            LEFT JOIN employees e
                ON e.id = ir.employee_id
            ORDER BY e.employee_code
        ")->fetchAll();

        foreach ($rows as $item) {
            row([
                $item['employee_code'],
                $item['full_name'],
                "job_code=" . ($item['job_code'] ?? 'NULL'),
                "job_code_id=" . ($item['insurance_job_code_id'] ?? 'NULL'),
                "insurance_no=" . ($item['insurance_number'] ?? 'NULL'),
                "insured_salary=" . ($item['insured_salary'] ?? 'NULL'),
                "status=" . ($item['status'] ?? 'NULL')
            ]);
        }

        subSection("Insurance records with missing employees");

        $rows = $pdo->query("
            SELECT ir.id, ir.employee_id
            FROM insurance_records ir
            LEFT JOIN employees e
                ON e.id = ir.employee_id
            WHERE e.id IS NULL
        ")->fetchAll();

        if (!$rows) {
            echo "OK\n";
        } else {
            foreach ($rows as $item) {
                row([
                    $item['id'],
                    $item['employee_id']
                ]);
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 11. Salary / Payroll tables
    |--------------------------------------------------------------------------
    */

    section("11. SALARY / PAYROLL TABLES");

    foreach (['salaries', 'salary_structures', 'payrolls', 'payroll_runs'] as $tableName) {

        $exists = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
        ");

        $exists->execute([$tableName]);

        if ($exists->fetchColumn()) {

            $count = $pdo
                ->query("SELECT COUNT(*) FROM `{$tableName}`")
                ->fetchColumn();

            echo str_pad($tableName, 30) . " : {$count} records\n";

        } else {

            echo str_pad($tableName, 30) . " : NOT FOUND\n";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 12. Roles / Permissions
    |--------------------------------------------------------------------------
    */

    section("12. ROLES / PERMISSIONS");

    foreach ([
        'roles',
        'permissions',
        'role_has_permissions',
        'model_has_roles',
        'model_has_permissions'
    ] as $tableName) {

        $exists = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
        ");

        $exists->execute([$tableName]);

        if ($exists->fetchColumn()) {

            $count = $pdo
                ->query("SELECT COUNT(*) FROM `{$tableName}`")
                ->fetchColumn();

            echo str_pad($tableName, 30) . " : {$count} records\n";

        } else {

            echo str_pad($tableName, 30) . " : NOT FOUND\n";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 13. Nullable columns audit
    |--------------------------------------------------------------------------
    */

    section("13. NULLABILITY AUDIT");

    echo "Checking important tables for heavily NULL columns...\n";

    $importantTables = [
        'employees',
        'hr_requests',
        'leaves',
        'insurance_records'
    ];

    foreach ($importantTables as $tableName) {

        $exists = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
        ");

        $exists->execute([$tableName]);

        if (!$exists->fetchColumn()) {
            continue;
        }

        subSection("TABLE: {$tableName}");

        $cols = $pdo->query("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = " . $pdo->quote($tableName) . "
        ")->fetchAll();

        foreach ($cols as $col) {

            $column = $col['COLUMN_NAME'];

            try {

                $sql = "
                    SELECT
                        COUNT(*) AS total,
                        SUM(
                            CASE
                                WHEN `{$column}` IS NULL THEN 1
                                ELSE 0
                            END
                        ) AS nulls
                    FROM `{$tableName}`
                ";

                $stats = $pdo->query($sql)->fetch();

                $total = (int)$stats['total'];
                $nulls = (int)$stats['nulls'];

                if ($total > 0 && $nulls > 0) {

                    $percentage = round(
                        ($nulls / $total) * 100,
                        1
                    );

                    if ($percentage >= 50) {

                        row([
                            $column,
                            "NULL={$nulls}/{$total}",
                            "{$percentage}%"
                        ]);
                    }
                }

            } catch (Throwable $e) {

                echo "Could not inspect {$tableName}.{$column}\n";
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 14. Empty reference tables
    |--------------------------------------------------------------------------
    */

    section("14. EMPTY REFERENCE / MASTER TABLES");

    $referenceTables = [
        'branches',
        'departments',
        'jobs',
        'employee_statuses',
        'employment_types',
        'payment_methods',
        'insurance_job_codes',
        'leave_types'
    ];

    foreach ($referenceTables as $tableName) {

        $exists = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = ?
        ");

        $exists->execute([$tableName]);

        if (!$exists->fetchColumn()) {

            echo str_pad($tableName, 35) . " : NOT FOUND\n";
            continue;
        }

        $count = $pdo
            ->query("SELECT COUNT(*) FROM `{$tableName}`")
            ->fetchColumn();

        echo str_pad($tableName, 35) . " : {$count}\n";
    }

    /*
    |--------------------------------------------------------------------------
    | 15. Potential orphan records
    |--------------------------------------------------------------------------
    */

    section("15. ORPHAN / BROKEN REFERENCES");

    $checks = [

        [
            'name' => 'employees.department_id',
            'sql' => "
                SELECT COUNT(*)
                FROM employees e
                LEFT JOIN departments d ON d.id = e.department_id
                WHERE e.department_id IS NOT NULL
                AND d.id IS NULL
            "
        ],

        [
            'name' => 'employees.job_id',
            'sql' => "
                SELECT COUNT(*)
                FROM employees e
                LEFT JOIN jobs j ON j.id = e.job_id
                WHERE e.job_id IS NOT NULL
                AND j.id IS NULL
            "
        ],

        [
            'name' => 'employees.status_id',
            'sql' => "
                SELECT COUNT(*)
                FROM employees e
                LEFT JOIN employee_statuses s ON s.id = e.status_id
                WHERE e.status_id IS NOT NULL
                AND s.id IS NULL
            "
        ],

        [
            'name' => 'employees.branch_id',
            'sql' => "
                SELECT COUNT(*)
                FROM employees e
                LEFT JOIN branches b ON b.id = e.branch_id
                WHERE e.branch_id IS NOT NULL
                AND b.id IS NULL
            "
        ],

        [
            'name' => 'insurance_records.employee_id',
            'sql' => "
                SELECT COUNT(*)
                FROM insurance_records ir
                LEFT JOIN employees e ON e.id = ir.employee_id
                WHERE e.id IS NULL
            "
        ],

        [
            'name' => 'hr_requests.employee_id',
            'sql' => "
                SELECT COUNT(*)
                FROM hr_requests r
                LEFT JOIN employees e ON e.id = r.employee_id
                WHERE e.id IS NULL
            "
        ],

        [
            'name' => 'leaves.employee_id',
            'sql' => "
                SELECT COUNT(*)
                FROM leaves l
                LEFT JOIN employees e ON e.id = l.employee_id
                WHERE e.id IS NULL
            "
        ],

    ];

    foreach ($checks as $check) {

        try {

            $count = $pdo
                ->query($check['sql'])
                ->fetchColumn();

            echo str_pad($check['name'], 45)
                . " : "
                . $count
                . ($count == 0 ? "  [OK]" : "  [ATTENTION]")
                . "\n";

        } catch (Throwable $e) {

            echo str_pad($check['name'], 45)
                . " : NOT CHECKED\n";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 16. Migration history
    |--------------------------------------------------------------------------
    */

    section("16. MIGRATION STATUS");

    $migrationExists = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'migrations'
    ")->fetchColumn();

    if ($migrationExists) {

        $count = $pdo
            ->query("SELECT COUNT(*) FROM migrations")
            ->fetchColumn();

        echo "Migration records: {$count}\n";

        $rows = $pdo->query("
            SELECT migration, batch
            FROM migrations
            ORDER BY id DESC
            LIMIT 20
        ")->fetchAll();

        foreach ($rows as $migration) {

            row([
                $migration['batch'],
                $migration['migration']
            ]);
        }

    } else {

        echo "migrations table not found.\n";
    }

    /*
    |--------------------------------------------------------------------------
    | 17. Final summary
    |--------------------------------------------------------------------------
    */

    section("17. AUDIT COMPLETE");

    echo "The database was inspected in READ-ONLY mode.\n";
    echo "NO INSERT / UPDATE / DELETE / ALTER operations were executed.\n";
    echo "\n";
    echo "Use the output above to identify:\n";
    echo "1. Missing tables\n";
    echo "2. Missing relationships\n";
    echo "3. Broken references\n";
    echo "4. Duplicate data\n";
    echo "5. Missing indexes / unique constraints\n";
    echo "6. Weak nullable fields\n";
    echo "7. Payroll structure issues\n";
    echo "8. Request-system issues\n";
    echo "9. Insurance inconsistencies\n";
    echo "10. Master-data problems\n";
    echo "\n";
    echo "END OF AUDIT\n";

} catch (Throwable $e) {

    echo "\n";
    echo str_repeat("=", 90) . "\n";
    echo "DATABASE AUDIT FAILED\n";
    echo str_repeat("=", 90) . "\n";

    echo "Error: " . $e->getMessage() . "\n";
    echo "File : " . $e->getFile() . "\n";
    echo "Line : " . $e->getLine() . "\n";
}