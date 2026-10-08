<?php
declare(strict_types=1);

/**
 * Tazamun V12 - Database Schema Upgrade
 * Safe upgrade: does NOT DROP/TRUNCATE/DELETE existing business data.
 */

$host = '127.0.0.1';
$db   = 'tazamun_hr';
$user = 'amer';
$pass = 'aaaaaa';

try {
    $pdo = new PDO(
        "mysql:host={$host};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    $pdo->exec("SET NAMES utf8mb4");
    $pdo->exec("SET FOREIGN_KEY_CHECKS=0");

    function tableExists(PDO $pdo, string $table): bool {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    function columnExists(PDO $pdo, string $table, string $column): bool {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    }

    function addColumn(PDO $pdo, string $table, string $column, string $definition): void {
        if (!columnExists($pdo, $table, $column)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            echo "ADD COLUMN: {$table}.{$column}\n";
        }
    }

    function createTable(PDO $pdo, string $table, string $sql): void {
        if (!tableExists($pdo, $table)) {
            $pdo->exec($sql);
            echo "CREATE TABLE: {$table}\n";
        }
    }

    function seed(PDO $pdo, string $sql, array $params, string $label): void {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo "SEED: {$label}\n";
    }

    $pdo->beginTransaction();

    /*
     * 1) Employees - additional fields
     */
    foreach ([
        ['employee_photo', 'VARCHAR(500) NULL'],
        ['personal_email', 'VARCHAR(190) NULL'],
        ['emergency_contact_name', 'VARCHAR(255) NULL'],
        ['emergency_contact_phone', 'VARCHAR(50) NULL'],
        ['emergency_contact_relation', 'VARCHAR(100) NULL'],
        ['national_id_issue_date', 'DATE NULL'],
        ['national_id_expiry_date', 'DATE NULL'],
        ['national_id_place', 'VARCHAR(255) NULL'],
        ['military_status', 'VARCHAR(100) NULL'],
        ['bank_name', 'VARCHAR(150) NULL'],
        ['bank_account_number', 'VARCHAR(100) NULL'],
        ['iban', 'VARCHAR(100) NULL'],
        ['payment_method_id', 'BIGINT UNSIGNED NULL'],
    ] as [$column, $definition]) {
        addColumn($pdo, 'employees', $column, $definition);
    }

    /*
     * 2) Salary history
     */
    createTable($pdo, 'salary_history', "
        CREATE TABLE `salary_history` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT UNSIGNED NOT NULL,
            `salary_id` BIGINT UNSIGNED NULL,
            `effective_from` DATE NOT NULL,
            `effective_to` DATE NULL,
            `salary_type` VARCHAR(20) NOT NULL DEFAULT 'net',
            `salary_input` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `basic_salary` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `allowance_limit_pct` DECIMAL(5,2) NOT NULL DEFAULT 25,
            `allowance_limit` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `housing_allowance` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `transportation_allowance` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `meal_allowance` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `clothing_allowance` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `other_allowances` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `gross_salary` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `insurance_wage` DECIMAL(12,2) NULL,
            `employee_insurance` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `income_tax` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `martyrs_fund` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `net_salary` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `employer_insurance` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `employee_cost` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `status` VARCHAR(50) NOT NULL DEFAULT 'Active',
            `calculation_snapshot` LONGTEXT NULL,
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `salary_history_employee_idx` (`employee_id`),
            KEY `salary_history_effective_from_idx` (`effective_from`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * 3) Employee allowance records
     */
    createTable($pdo, 'employee_allowances', "
        CREATE TABLE `employee_allowances` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT UNSIGNED NOT NULL,
            `allowance_type` VARCHAR(100) NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `percentage` DECIMAL(8,4) NULL,
            `effective_from` DATE NOT NULL,
            `effective_to` DATE NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `notes` VARCHAR(500) NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `employee_allowances_employee_idx` (`employee_id`),
            KEY `employee_allowances_type_idx` (`allowance_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * 4) Payroll earnings
     */
    createTable($pdo, 'payroll_earnings', "
        CREATE TABLE `payroll_earnings` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `payroll_record_id` BIGINT UNSIGNED NOT NULL,
            `employee_id` BIGINT UNSIGNED NOT NULL,
            `earning_type` VARCHAR(100) NOT NULL,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `taxable` TINYINT(1) NOT NULL DEFAULT 1,
            `insurance_subject` TINYINT(1) NOT NULL DEFAULT 1,
            `notes` VARCHAR(500) NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `payroll_earnings_record_idx` (`payroll_record_id`),
            KEY `payroll_earnings_employee_idx` (`employee_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * 5) Overtime
     */
    createTable($pdo, 'overtime_records', "
        CREATE TABLE `overtime_records` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT UNSIGNED NOT NULL,
            `payroll_record_id` BIGINT UNSIGNED NULL,
            `overtime_date` DATE NOT NULL,
            `overtime_type` VARCHAR(50) NOT NULL,
            `hours` DECIMAL(10,2) NOT NULL DEFAULT 0,
            `days` DECIMAL(10,2) NOT NULL DEFAULT 0,
            `rate_multiplier` DECIMAL(8,4) NOT NULL DEFAULT 1,
            `hourly_base` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `amount` DECIMAL(12,2) NOT NULL DEFAULT 0,
            `status` VARCHAR(50) NOT NULL DEFAULT 'Pending',
            `approved_by` BIGINT UNSIGNED NULL,
            `approved_at` TIMESTAMP NULL,
            `notes` VARCHAR(500) NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `overtime_employee_idx` (`employee_id`),
            KEY `overtime_date_idx` (`overtime_date`),
            KEY `overtime_payroll_idx` (`payroll_record_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * 6) Payroll approval snapshots
     */
    createTable($pdo, 'payroll_snapshots', "
        CREATE TABLE `payroll_snapshots` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `payroll_record_id` BIGINT UNSIGNED NOT NULL,
            `employee_id` BIGINT UNSIGNED NOT NULL,
            `payroll_month` DATE NOT NULL,
            `snapshot_type` VARCHAR(50) NOT NULL DEFAULT 'approval',
            `snapshot_data` LONGTEXT NOT NULL,
            `created_by` BIGINT UNSIGNED NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `payroll_snapshots_record_idx` (`payroll_record_id`),
            KEY `payroll_snapshots_employee_idx` (`employee_id`),
            KEY `payroll_snapshots_month_idx` (`payroll_month`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * 7) Tax year configuration
     */
    createTable($pdo, 'tax_year_settings', "
        CREATE TABLE `tax_year_settings` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `tax_year` SMALLINT UNSIGNED NOT NULL,
            `personal_exemption` DECIMAL(14,2) NOT NULL DEFAULT 20000,
            `tax_deductible_contributions` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `profit_share_tax_exempt` TINYINT(1) NOT NULL DEFAULT 0,
            `monthly_tax_rounding` VARCHAR(30) NOT NULL DEFAULT 'none',
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `notes` VARCHAR(500) NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `tax_year_settings_year_unique` (`tax_year`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * 8) Employee tax year tracking
     */
    createTable($pdo, 'employee_tax_years', "
        CREATE TABLE `employee_tax_years` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT UNSIGNED NOT NULL,
            `tax_year` SMALLINT UNSIGNED NOT NULL,
            `annual_gross` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `annual_employee_insurance` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `deductible_contributions` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `exempt_profit_share` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `personal_exemption` DECIMAL(14,2) NOT NULL DEFAULT 20000,
            `taxable_income` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `tax_category` VARCHAR(30) NULL,
            `annual_tax` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `monthly_tax_paid` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `tax_difference` DECIMAL(14,2) NOT NULL DEFAULT 0,
            `status` VARCHAR(30) NOT NULL DEFAULT 'Open',
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `employee_tax_year_unique` (`employee_id`,`tax_year`),
            KEY `employee_tax_year_status_idx` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * 9) Document types
     */
    createTable($pdo, 'document_types', "
        CREATE TABLE `document_types` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(150) NOT NULL,
            `is_required` TINYINT(1) NOT NULL DEFAULT 0,
            `has_expiry` TINYINT(1) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `document_types_name_unique` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * 10) Banks
     */
    createTable($pdo, 'banks', "
        CREATE TABLE `banks` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(150) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `banks_name_unique` (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * 11) Insurance job codes
     */
    createTable($pdo, 'insurance_job_codes', "
        CREATE TABLE `insurance_job_codes` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(50) NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `insurance_job_codes_code_unique` (`code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * 12) Employee bank accounts
     */
    createTable($pdo, 'employee_bank_accounts', "
        CREATE TABLE `employee_bank_accounts` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `employee_id` BIGINT UNSIGNED NOT NULL,
            `bank_id` BIGINT UNSIGNED NULL,
            `account_holder_name` VARCHAR(255) NULL,
            `account_number` VARCHAR(100) NULL,
            `iban` VARCHAR(100) NULL,
            `is_primary` TINYINT(1) NOT NULL DEFAULT 1,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `notes` VARCHAR(500) NULL,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `employee_bank_accounts_employee_idx` (`employee_id`),
            KEY `employee_bank_accounts_bank_idx` (`bank_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * 13) Central system settings
     */
    createTable($pdo, 'system_settings', "
        CREATE TABLE `system_settings` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `setting_key` VARCHAR(255) NOT NULL,
            `setting_value` TEXT NULL,
            `setting_group` VARCHAR(100) NOT NULL DEFAULT 'general',
            `label` VARCHAR(255) NULL,
            `is_public` TINYINT(1) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `system_settings_key_unique` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    /*
     * 14) Payroll locking / approval fields
     */
    foreach ([
        ['payroll_runs', 'locked_at', 'TIMESTAMP NULL'],
        ['payroll_runs', 'locked_by', 'BIGINT UNSIGNED NULL'],
        ['payroll_runs', 'finalized_at', 'TIMESTAMP NULL'],
        ['payroll_runs', 'finalized_by', 'BIGINT UNSIGNED NULL'],
        ['payroll_records', 'approved_at', 'TIMESTAMP NULL'],
        ['payroll_records', 'approved_by', 'BIGINT UNSIGNED NULL'],
        ['payroll_records', 'is_locked', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['payroll_records', 'year_to_date_tax', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['payroll_records', 'year_to_date_gross', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['payroll_records', 'year_to_date_insurance', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
    ] as [$table, $column, $definition]) {
        addColumn($pdo, $table, $column, $definition);
    }

    /*
     * 15) Attendance fields
     */
    foreach ([
        ['scheduled_check_in', 'TIME NULL'],
        ['scheduled_check_out', 'TIME NULL'],
        ['worked_minutes', 'INT UNSIGNED NOT NULL DEFAULT 0'],
        ['absence_minutes', 'INT UNSIGNED NOT NULL DEFAULT 0'],
        ['attendance_source', 'VARCHAR(50) NULL'],
        ['approved_by', 'BIGINT UNSIGNED NULL'],
        ['approved_at', 'TIMESTAMP NULL'],
    ] as [$column, $definition]) {
        addColumn($pdo, 'attendance', $column, $definition);
    }

    /*
     * 16) Insurance fields
     */
    foreach ([
        ['insurance_job_code_id', 'BIGINT UNSIGNED NULL'],
        ['insurance_status', 'VARCHAR(50) NULL'],
        ['registration_date', 'DATE NULL'],
        ['termination_date', 'DATE NULL'],
    ] as [$column, $definition]) {
        addColumn($pdo, 'insurance_records', $column, $definition);
    }

    /*
     * 17) Documents
     */
    foreach ([
        ['document_type_id', 'BIGINT UNSIGNED NULL'],
        ['is_required', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['version_number', 'INT UNSIGNED NOT NULL DEFAULT 1'],
        ['replaced_document_id', 'BIGINT UNSIGNED NULL'],
    ] as [$column, $definition]) {
        addColumn($pdo, 'documents', $column, $definition);
    }

    /*
     * 18) Employee movements
     */
    foreach ([
        ['old_branch_id', 'BIGINT UNSIGNED NULL'],
        ['new_branch_id', 'BIGINT UNSIGNED NULL'],
        ['old_manager_id', 'BIGINT UNSIGNED NULL'],
        ['new_manager_id', 'BIGINT UNSIGNED NULL'],
        ['old_contract_type', 'VARCHAR(100) NULL'],
        ['new_contract_type', 'VARCHAR(100) NULL'],
    ] as [$column, $definition]) {
        addColumn($pdo, 'movement_records', $column, $definition);
    }

    /*
     * 19) Insurance settings
     */
    $insuranceSettings = [
        ['MIN_INSURANCE_WAGE', 2700, 'الحد الأدنى للأجر التأميني'],
        ['MAX_INSURANCE_WAGE', 16700, 'الحد الأقصى للأجر التأميني'],
        ['EMPLOYEE_RATE', 0.11, 'نسبة حصة الموظف'],
        ['EMPLOYER_RATE', 0.1875, 'نسبة حصة صاحب العمل'],
        ['MARTYRS_RATE', 0.0005, 'صندوق أسر الشهداء'],
        ['ALLOWANCE_EXCLUSION_DEFAULT', 25, 'نسبة استبعاد البدلات الافتراضية'],
    ];

    foreach ($insuranceSettings as [$key, $value, $label]) {
        seed(
            $pdo,
            "INSERT INTO insurance_settings (setting_key, setting_value, label)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), label = VALUES(label)",
            [$key, $value, $label],
            "insurance_settings {$key}"
        );
    }

    /*
     * 20) Tax settings
     */
    $taxSettings = [
        ['PERSONAL_EXEMPTION', 20000, 'الإعفاء الشخصي السنوي'],
        ['MONTHLY_PERSONAL_EXEMPTION', 1666.666667, 'الإعفاء الشخصي الشهري'],
        ['TAX_YEAR', 2026, 'السنة الضريبية الحالية'],
    ];

    foreach ($taxSettings as [$key, $value, $label]) {
        seed(
            $pdo,
            "INSERT INTO tax_settings (setting_key, setting_value, label)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), label = VALUES(label)",
            [$key, $value, $label],
            "tax_settings {$key}"
        );
    }

    /*
     * 21) Current tax year
     */
    seed(
        $pdo,
        "INSERT INTO tax_year_settings
            (tax_year, personal_exemption, tax_deductible_contributions, profit_share_tax_exempt, monthly_tax_rounding, is_active)
         VALUES (2026, 20000, 0, 0, 'none', 1)
         ON DUPLICATE KEY UPDATE
            personal_exemption = VALUES(personal_exemption),
            is_active = VALUES(is_active)",
        [],
        "tax year 2026"
    );

    /*
     * 22) System settings
     */
    $systemSettings = [
        ['company_name', 'شركة تزامن لتقنية المعلومات', 'general', 'اسم الشركة'],
        ['default_currency', 'EGP', 'payroll', 'العملة الافتراضية'],
        ['allowance_limit_pct', '25', 'payroll', 'نسبة إجمالي البدلات'],
        ['working_days', 'Sunday,Monday,Tuesday,Wednesday,Thursday', 'attendance', 'أيام العمل'],
        ['daily_work_hours', '8', 'attendance', 'ساعات العمل اليومية'],
        ['work_start_time', '10:00', 'attendance', 'بداية العمل'],
        ['work_end_time', '18:00', 'attendance', 'نهاية العمل'],
    ];

    foreach ($systemSettings as [$key, $value, $group, $label]) {
        seed(
            $pdo,
            "INSERT INTO system_settings (setting_key, setting_value, setting_group, label)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                setting_group = VALUES(setting_group),
                label = VALUES(label)",
            [$key, $value, $group, $label],
            "system_settings {$key}"
        );
    }

    /*
     * 23) Insurance job codes already agreed for the current employees
     */
    seed(
        $pdo,
        "INSERT INTO insurance_job_codes (code, title)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE title = VALUES(title)",
        ['243105', 'أخصائي تسويق'],
        'insurance code 243105'
    );

    seed(
        $pdo,
        "INSERT INTO insurance_job_codes (code, title)
         VALUES (?, ?)
         ON DUPLICATE KEY UPDATE title = VALUES(title)",
        ['264101', 'كاتب مؤلفات عام (مؤلف)'],
        'insurance code 264101'
    );

    /*
     * 24) Basic master data - insert only if missing
     */
    $masterData = [
        ['leave_types', 'سنوية'],
        ['leave_types', 'مرضية'],
        ['leave_types', 'عارضة'],
        ['leave_types', 'بدون أجر'],

        ['movement_types', 'تعيين'],
        ['movement_types', 'ترقية'],
        ['movement_types', 'نقل قسم'],
        ['movement_types', 'نقل وظيفة'],
        ['movement_types', 'تغيير راتب'],
        ['movement_types', 'نقل فرع'],

        ['payment_methods', 'تحويل بنكي'],
        ['payment_methods', 'نقدي'],
        ['payment_methods', 'تحويل محفظة'],

        ['penalty_types', 'إنذار شفهي'],
        ['penalty_types', 'إنذار كتابي'],
        ['penalty_types', 'خصم'],
        ['penalty_types', 'جزاء إداري'],
    ];

    foreach ($masterData as [$table, $name]) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM `$table` WHERE `name` = ?");
        $stmt->execute([$name]);

        if ((int)$stmt->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO `$table` (`name`) VALUES (?)")->execute([$name]);
            echo "MASTER DATA: {$table} -> {$name}\n";
        }
    }

    $pdo->commit();
    $pdo->exec("SET FOREIGN_KEY_CHECKS=1");

    echo "\n========================================\n";
    echo "Tazamun V12 DATABASE UPGRADE DONE\n";
    echo "NO EXISTING BUSINESS DATA WAS DELETED\n";
    echo "========================================\n";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    try {
        $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
    } catch (Throwable $ignored) {}

    echo "\nERROR: " . $e->getMessage() . "\n";
    exit(1);
}
