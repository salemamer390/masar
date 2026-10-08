<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

try {
    require __DIR__ . '/vendor/autoload.php';

    /** @var \Illuminate\Foundation\Application $app */
    $app = require __DIR__ . '/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    // The connection is sourced exclusively from the active Laravel environment.
    // No database credentials are stored in this read-only audit script.
    $pdo = DB::connection()->getPdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    echo "============================================================\n";
    echo " TAZAMUN V12 - PAYROLL DATABASE AUDIT\n";
    echo " READ ONLY - NO DATABASE CHANGES\n";
    echo "============================================================\n\n";

    function section(string $title): void
    {
        echo "\n============================================================\n";
        echo " {$title}\n";
        echo "============================================================\n";
    }

    function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
            AND table_name = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    function showColumns(PDO $pdo, string $table): void
    {
        if (!tableExists($pdo, $table)) {
            echo "TABLE NOT FOUND: {$table}\n";
            return;
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}`");

        foreach ($stmt as $row) {
            echo sprintf(
                "%-35s | %-25s | %-3s | %-10s | %-20s\n",
                $row['Field'],
                $row['Type'],
                $row['Null'],
                $row['Key'],
                (string)$row['Default']
            );
        }
    }

    function showCount(PDO $pdo, string $table): void
    {
        if (!tableExists($pdo, $table)) {
            echo "{$table}: NOT FOUND\n";
            return;
        }

        $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        echo "{$table}: {$count} rows\n";
    }

    /*
    |--------------------------------------------------------------------------
    | 1. Core Payroll Tables
    |--------------------------------------------------------------------------
    */

    section('1. CORE PAYROLL TABLES');

    $coreTables = [
        'salaries',
        'salary_history',
        'employee_allowances',
        'payroll_records',
        'payroll_runs',
        'payroll_earnings',
        'payroll_deductions',
        'overtime_records',
        'payroll_snapshots',
    ];

    foreach ($coreTables as $table) {
        showCount($pdo, $table);
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Salary Structure
    |--------------------------------------------------------------------------
    */

    section('2. SALARIES - STRUCTURE');

    showColumns($pdo, 'salaries');

    /*
    |--------------------------------------------------------------------------
    | 3. Payroll Settings
    |--------------------------------------------------------------------------
    */

    section('3. PAYROLL CALCULATION SETTINGS');

    showColumns($pdo, 'payroll_calculation_settings');

    if (tableExists($pdo, 'payroll_calculation_settings')) {
        echo "\nCURRENT SETTINGS:\n";

        $rows = $pdo->query("
            SELECT setting_group, setting_key, setting_value, is_active
            FROM payroll_calculation_settings
            ORDER BY setting_group, setting_key
        ")->fetchAll();

        foreach ($rows as $row) {
            echo sprintf(
                "%-15s | %-35s | %-15s | active=%s\n",
                $row['setting_group'],
                $row['setting_key'],
                $row['setting_value'],
                $row['is_active']
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Payroll Rules
    |--------------------------------------------------------------------------
    */

    section('4. PAYROLL CALCULATION RULES');

    showColumns($pdo, 'payroll_calculation_rules');

    if (tableExists($pdo, 'payroll_calculation_rules')) {
        echo "\nCURRENT RULES:\n";

        $rows = $pdo->query("
            SELECT rule_group, rule_key, rule_value, is_active
            FROM payroll_calculation_rules
            ORDER BY rule_group, rule_key
        ")->fetchAll();

        foreach ($rows as $row) {
            echo sprintf(
                "%-15s | %-35s | %-15s | active=%s\n",
                $row['rule_group'],
                $row['rule_key'],
                $row['rule_value'],
                $row['is_active']
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Allowance Distribution
    |--------------------------------------------------------------------------
    */

    section('5. ALLOWANCE DISTRIBUTION');

    showColumns($pdo, 'payroll_allowance_settings');

    if (tableExists($pdo, 'payroll_allowance_settings')) {
        $rows = $pdo->query("
            SELECT
                allowance_key,
                allowance_name,
                percentage,
                calculation_basis,
                sort_order,
                is_active
            FROM payroll_allowance_settings
            ORDER BY sort_order
        ")->fetchAll();

        $total = 0;

        echo "\nALLOWANCE DISTRIBUTION:\n";

        foreach ($rows as $row) {
            $total += (float)$row['percentage'];

            echo sprintf(
                "%-20s | %-10s | %-10s | basis=%-10s | active=%s\n",
                $row['allowance_key'],
                $row['percentage'] . '%',
                $row['allowance_name'],
                $row['calculation_basis'],
                $row['is_active']
            );
        }

        echo "\nTOTAL DISTRIBUTION = " . number_format($total, 2) . "%\n";

        if (abs($total - 100) < 0.0001) {
            echo "STATUS: OK - Distribution totals 100%\n";
        } else {
            echo "STATUS: WARNING - Distribution does NOT total 100%\n";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 6. Tax Tables
    |--------------------------------------------------------------------------
    */

    section('6. TAX TABLES');

    $taxTables = [
        'tax_settings',
        'tax_year_settings',
        'tax_categories',
        'tax_brackets',
        'tax_reconciliations',
        'employee_tax_years',
    ];

    foreach ($taxTables as $table) {
        showCount($pdo, $table);
    }

    if (tableExists($pdo, 'tax_year_settings')) {
        echo "\nTAX YEAR SETTINGS:\n";

        $rows = $pdo->query("
            SELECT *
            FROM tax_year_settings
            ORDER BY tax_year DESC
        ")->fetchAll();

        foreach ($rows as $row) {
            echo json_encode(
                $row,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) . "\n";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 7. Insurance Tables
    |--------------------------------------------------------------------------
    */

    section('7. INSURANCE');

    $insuranceTables = [
        'insurance_settings',
        'insurance_job_codes',
        'insurance_records',
    ];

    foreach ($insuranceTables as $table) {
        showCount($pdo, $table);
    }

    if (tableExists($pdo, 'insurance_settings')) {
        echo "\nINSURANCE SETTINGS:\n";

        $rows = $pdo->query("
            SELECT *
            FROM insurance_settings
            ORDER BY id
        ")->fetchAll();

        foreach ($rows as $row) {
            echo json_encode(
                $row,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) . "\n";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 8. Payroll Records Structure
    |--------------------------------------------------------------------------
    */

    section('8. PAYROLL RECORDS - STRUCTURE');

    showColumns($pdo, 'payroll_records');

    /*
    |--------------------------------------------------------------------------
    | 9. Payroll Runs
    |--------------------------------------------------------------------------
    */

    section('9. PAYROLL RUNS - STRUCTURE');

    showColumns($pdo, 'payroll_runs');

    /*
    |--------------------------------------------------------------------------
    | 10. Earnings / Deductions / Overtime
    |--------------------------------------------------------------------------
    */

    section('10. EARNINGS / DEDUCTIONS / OVERTIME');

    foreach ([
        'payroll_earnings',
        'payroll_deductions',
        'overtime_records',
        'payroll_snapshots',
    ] as $table) {
        echo "\n--- {$table} ---\n";
        showColumns($pdo, $table);
    }

    /*
    |--------------------------------------------------------------------------
    | 11. Employee Salary Relations
    |--------------------------------------------------------------------------
    */

    section('11. EMPLOYEE / SALARY RELATION');

    if (tableExists($pdo, 'employees')) {
        showCount($pdo, 'employees');
    }

    if (tableExists($pdo, 'salaries')) {
        $rows = $pdo->query("
            SELECT
                s.id,
                s.employee_id,
                e.employee_code,
                e.full_name,
                s.salary_type,
                s.salary_input,
                s.basic_salary,
                s.allowances,
                s.allowance_limit_pct,
                s.allowance_limit,
                s.allowance_remaining,
                s.housing_allowance,
                s.transportation_allowance,
                s.meal_allowance,
                s.clothing_allowance,
                s.gross_salary,
                s.net_salary,
                s.insurance_wage,
                s.applied_insurance_wage,
                s.employee_insurance,
                s.income_tax,
                s.martyrs_fund,
                s.total_deductions
            FROM salaries s
            LEFT JOIN employees e
                ON e.id = s.employee_id
            ORDER BY e.employee_code
        ")->fetchAll();

        if (!$rows) {
            echo "No salary structures found.\n";
        } else {
            foreach ($rows as $row) {
                echo json_encode(
                    $row,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ) . "\n";
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 12. Critical Logic Checks
    |--------------------------------------------------------------------------
    */

    section('12. CRITICAL LOGIC CHECKS');

    echo "Expected allowance rate: 25% of BASIC\n";
    echo "Expected allowance distribution: 35 / 30 / 20 / 15\n";
    echo "Expected absence basis: Gross\n";
    echo "Expected penalty basis: Basic\n";
    echo "Expected overtime basis: Gross\n";
    echo "Expected automatic insurance basis: Gross\n";
    echo "Expected employee insurance: 11%\n";
    echo "Expected employer insurance: 18.75%\n";
    echo "Expected martyrs fund: 0.05%\n";
    echo "Expected insurance range: 2700 - 16700\n";
    echo "Expected personal exemption: 20000 annually\n";

    /*
    |--------------------------------------------------------------------------
    | 13. Foreign Keys
    |--------------------------------------------------------------------------
    */

    section('13. FOREIGN KEYS');

    $stmt = $pdo->query("
        SELECT
            TABLE_NAME,
            COLUMN_NAME,
            CONSTRAINT_NAME,
            REFERENCED_TABLE_NAME,
            REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND REFERENCED_TABLE_NAME IS NOT NULL
          AND (
                TABLE_NAME IN (
                    'salaries',
                    'salary_history',
                    'employee_allowances',
                    'payroll_records',
                    'payroll_runs',
                    'payroll_earnings',
                    'payroll_deductions',
                    'overtime_records',
                    'payroll_snapshots'
                )
                OR REFERENCED_TABLE_NAME IN (
                    'salaries',
                    'payroll_records',
                    'payroll_runs',
                    'employees'
                )
          )
        ORDER BY TABLE_NAME, COLUMN_NAME
    ");

    foreach ($stmt as $row) {
        echo sprintf(
            "%-25s.%-25s -> %-25s.%s\n",
            $row['TABLE_NAME'],
            $row['COLUMN_NAME'],
            $row['REFERENCED_TABLE_NAME'],
            $row['REFERENCED_COLUMN_NAME']
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 14. Final
    |--------------------------------------------------------------------------
    */

    section('AUDIT COMPLETE');

    echo "NO DATA WAS INSERTED.\n";
    echo "NO DATA WAS UPDATED.\n";
    echo "NO DATA WAS DELETED.\n";
    echo "NO TABLE WAS CREATED OR DROPPED.\n";

} catch (Throwable $e) {
    echo "\nERROR:\n";
    echo $e->getMessage() . "\n";
    exit(1);
}
