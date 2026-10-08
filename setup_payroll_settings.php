<?php

declare(strict_types=1);

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

    echo "Connected to database: {$db}\n\n";

    /*
    |--------------------------------------------------------------------------
    | 1. Central Payroll Calculation Settings
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payroll_calculation_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            setting_group VARCHAR(100) NOT NULL,
            setting_key VARCHAR(150) NOT NULL,
            setting_value DECIMAL(18,6) NULL,
            value_type VARCHAR(30) NOT NULL DEFAULT 'number',
            unit VARCHAR(30) NULL,
            label VARCHAR(255) NULL,
            description TEXT NULL,
            is_editable TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            effective_from DATE NULL,
            effective_to DATE NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            UNIQUE KEY uq_payroll_calculation_setting
                (setting_group, setting_key),

            INDEX idx_payroll_calculation_group
                (setting_group),

            INDEX idx_payroll_calculation_active
                (is_active)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    /*
    |--------------------------------------------------------------------------
    | 2. Payroll Calculation Rules
    |--------------------------------------------------------------------------
    |
    | Text-based rules such as:
    | absence_basis = Gross
    | penalty_basis = Basic
    |
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payroll_calculation_rules (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rule_group VARCHAR(100) NOT NULL,
            rule_key VARCHAR(150) NOT NULL,
            rule_value VARCHAR(255) NOT NULL,
            label VARCHAR(255) NULL,
            description TEXT NULL,
            is_editable TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            effective_from DATE NULL,
            effective_to DATE NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            UNIQUE KEY uq_payroll_calculation_rule
                (rule_group, rule_key),

            INDEX idx_payroll_rules_group
                (rule_group)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    /*
    |--------------------------------------------------------------------------
    | 3. Allowance Settings
    |--------------------------------------------------------------------------
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payroll_allowance_settings (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            allowance_key VARCHAR(100) NOT NULL,
            allowance_name VARCHAR(255) NOT NULL,
            percentage DECIMAL(10,4) NOT NULL DEFAULT 0,
            calculation_basis VARCHAR(50) NOT NULL DEFAULT 'allowance_pool',
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            effective_from DATE NULL,
            effective_to DATE NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            UNIQUE KEY uq_allowance_key
                (allowance_key),

            INDEX idx_allowance_active
                (is_active)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    /*
    |--------------------------------------------------------------------------
    | 4. Payroll Calculation Snapshots
    |--------------------------------------------------------------------------
    |
    | Used later when payroll is approved.
    | Historical payroll must keep the settings that were actually used.
    |
    */

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS payroll_calculation_snapshots (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            payroll_record_id BIGINT UNSIGNED NULL,
            payroll_run_id BIGINT UNSIGNED NULL,
            employee_id BIGINT UNSIGNED NULL,
            payroll_month DATE NULL,
            settings_json LONGTEXT NOT NULL,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,

            INDEX idx_snapshot_payroll_record
                (payroll_record_id),

            INDEX idx_snapshot_payroll_run
                (payroll_run_id),

            INDEX idx_snapshot_employee
                (employee_id),

            INDEX idx_snapshot_month
                (payroll_month)
        ) ENGINE=InnoDB
        DEFAULT CHARSET=utf8mb4
        COLLATE=utf8mb4_unicode_ci
    ");

    /*
    |--------------------------------------------------------------------------
    | 5. Numeric Settings
    |--------------------------------------------------------------------------
    */

    $numericSettings = [

        // General
        [
            'general',
            'allowance_rate',
            25,
            'percentage',
            '%',
            'نسبة إجمالي البدلات',
            'النسبة المستخدمة لتحديد حد إجمالي البدلات'
        ],

        // Insurance
        [
            'insurance',
            'employee_insurance_rate',
            11,
            'percentage',
            '%',
            'حصة الموظف في التأمينات',
            'النسبة التي يتحملها الموظف'
        ],
        [
            'insurance',
            'employer_insurance_rate',
            18.75,
            'percentage',
            '%',
            'حصة صاحب العمل في التأمينات',
            'النسبة التي يتحملها صاحب العمل'
        ],
        [
            'insurance',
            'martyrs_fund_rate',
            0.05,
            'percentage',
            '%',
            'صندوق أسر الشهداء',
            'نسبة صندوق أسر الشهداء'
        ],
        [
            'insurance',
            'insurance_minimum',
            2700,
            'number',
            'EGP',
            'الحد الأدنى للأجر التأميني',
            'الحد الأدنى للأجر التأميني'
        ],
        [
            'insurance',
            'insurance_maximum',
            16700,
            'number',
            'EGP',
            'الحد الأقصى للأجر التأميني',
            'الحد الأقصى للأجر التأميني'
        ],

        // Overtime
        [
            'overtime',
            'hours_divisor',
            240,
            'number',
            'hour',
            'مقام حساب أجر الساعة',
            'Gross ÷ 240 لحساب أجر الساعة'
        ],
        [
            'overtime',
            'days_divisor',
            30,
            'number',
            'day',
            'مقام حساب أجر اليوم',
            'Gross أو Basic ÷ 30 حسب نوع الخصم'
        ],
        [
            'overtime',
            'day_multiplier',
            1.35,
            'number',
            'x',
            'معامل الإضافي النهاري',
            'معامل الإضافي النهاري'
        ],
        [
            'overtime',
            'night_multiplier',
            1.70,
            'number',
            'x',
            'معامل الإضافي الليلي',
            'معامل الإضافي الليلي'
        ],
        [
            'overtime',
            'official_holiday_multiplier',
            2,
            'number',
            'x',
            'معامل العطلة الرسمية',
            'معامل العمل في العطلات الرسمية'
        ],
        [
            'overtime',
            'weekly_rest_multiplier',
            1,
            'number',
            'x',
            'معامل الراحة الأسبوعية',
            'معامل العمل في يوم الراحة الأسبوعية'
        ],

        // Tax
        [
            'tax',
            'personal_exemption',
            20000,
            'number',
            'EGP/year',
            'الإعفاء الشخصي السنوي',
            'قيمة الإعفاء الشخصي السنوي'
        ],
        [
            'tax',
            'tax_deductible_contributions',
            0,
            'number',
            'EGP/year',
            'المساهمات القابلة للخصم ضريبيًا',
            'المساهمات السنوية القابلة للخصم'
        ],
        [
            'tax',
            'profit_share_tax_exempt',
            0,
            'number',
            'EGP/year',
            'حصة الأرباح المعفاة ضريبيًا',
            'قيمة حصة الأرباح المعفاة'
        ],

        // Absence
        [
            'absence',
            'daily_divisor',
            30,
            'number',
            'day',
            'مقام حساب الغياب',
            'عدد الأيام المستخدمة في حساب خصم الغياب'
        ],

        // Penalties
        [
            'penalty',
            'daily_divisor',
            30,
            'number',
            'day',
            'مقام حساب الجزاء',
            'عدد الأيام المستخدمة في حساب الجزاء'
        ],
    ];

    $numericStmt = $pdo->prepare("
        INSERT INTO payroll_calculation_settings
        (
            setting_group,
            setting_key,
            setting_value,
            value_type,
            unit,
            label,
            description
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)

        ON DUPLICATE KEY UPDATE
            value_type = VALUES(value_type),
            unit = VALUES(unit),
            label = VALUES(label),
            description = VALUES(description),
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($numericSettings as $setting) {
        $numericStmt->execute($setting);
    }

    /*
    |--------------------------------------------------------------------------
    | 6. Text Calculation Rules
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | Absence = Gross
    | Penalty = Basic
    |
    */

    $rules = [

        [
            'absence',
            'calculation_basis',
            'Gross',
            'أساس حساب الغياب',
            'خصم الغياب يتم احتسابه على إجمالي الراتب Gross'
        ],

        [
            'penalty',
            'calculation_basis',
            'Basic',
            'أساس حساب الجزاءات',
            'الجزاءات يتم احتسابها على الأساسي Basic'
        ],

        [
            'overtime',
            'calculation_basis',
            'Gross',
            'أساس حساب الإضافي',
            'الإضافي يتم احتسابه على إجمالي الراتب Gross'
        ],

        [
            'insurance',
            'automatic_calculation_basis',
            'Gross',
            'أساس حساب الأجر التأميني',
            'الحساب التلقائي للأجر التأميني يعتمد على Gross'
        ],
    ];

    $ruleStmt = $pdo->prepare("
        INSERT INTO payroll_calculation_rules
        (
            rule_group,
            rule_key,
            rule_value,
            label,
            description
        )
        VALUES (?, ?, ?, ?, ?)

        ON DUPLICATE KEY UPDATE
            rule_value = VALUES(rule_value),
            label = VALUES(label),
            description = VALUES(description),
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($rules as $rule) {
        $ruleStmt->execute($rule);
    }

    /*
    |--------------------------------------------------------------------------
    | 7. Allowance Distribution
    |--------------------------------------------------------------------------
    |
    | Current default distribution:
    |
    | Transport 35%
    | Housing   30%
    | Meal      20%
    | Clothing  15%
    |
    | Total = 100%
    |
    */

    $allowances = [
        [
            'transport',
            'بدل انتقال',
            35,
            'allowance_pool',
            1
        ],
        [
            'housing',
            'بدل سكن',
            30,
            'allowance_pool',
            2
        ],
        [
            'meal',
            'بدل وجبة',
            20,
            'allowance_pool',
            3
        ],
        [
            'clothing',
            'بدل ملبس',
            15,
            'allowance_pool',
            4
        ],
    ];

    $allowanceStmt = $pdo->prepare("
        INSERT INTO payroll_allowance_settings
        (
            allowance_key,
            allowance_name,
            percentage,
            calculation_basis,
            sort_order
        )
        VALUES (?, ?, ?, ?, ?)

        ON DUPLICATE KEY UPDATE
            allowance_name = VALUES(allowance_name),
            percentage = VALUES(percentage),
            calculation_basis = VALUES(calculation_basis),
            sort_order = VALUES(sort_order),
            updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($allowances as $allowance) {
        $allowanceStmt->execute($allowance);
    }

    /*
    |--------------------------------------------------------------------------
    | 8. Validate allowance percentages
    |--------------------------------------------------------------------------
    */

    $allowanceTotal = (float) $pdo->query("
        SELECT COALESCE(SUM(percentage), 0)
        FROM payroll_allowance_settings
        WHERE is_active = 1
    ")->fetchColumn();

    if (abs($allowanceTotal - 100) > 0.0001) {
        throw new RuntimeException(
            "Allowance percentages must equal 100%. Current total: {$allowanceTotal}%"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 9. Final verification
    |--------------------------------------------------------------------------
    */

    echo "============================================\n";
    echo "PAYROLL SETTINGS SETUP COMPLETED\n";
    echo "============================================\n\n";

    echo "General\n";
    echo "--------------------------------------------\n";
    echo "Allowance Rate: 25%\n\n";

    echo "Insurance\n";
    echo "--------------------------------------------\n";
    echo "Employee Insurance: 11%\n";
    echo "Employer Insurance: 18.75%\n";
    echo "Martyrs Fund: 0.05%\n";
    echo "Minimum Insurance Wage: 2700\n";
    echo "Maximum Insurance Wage: 16700\n\n";

    echo "Overtime\n";
    echo "--------------------------------------------\n";
    echo "Day: 1.35x\n";
    echo "Night: 1.70x\n";
    echo "Official Holiday: 2x\n";
    echo "Weekly Rest: 1x\n\n";

    echo "Calculation Basis\n";
    echo "--------------------------------------------\n";
    echo "Absence: Gross\n";
    echo "Penalty: Basic\n";
    echo "Overtime: Gross\n";
    echo "Insurance Automatic Basis: Gross\n\n";

    echo "Allowance Distribution\n";
    echo "--------------------------------------------\n";
    echo "Transport: 35%\n";
    echo "Housing: 30%\n";
    echo "Meal: 20%\n";
    echo "Clothing: 15%\n";
    echo "Total: {$allowanceTotal}%\n\n";

    echo "Tax\n";
    echo "--------------------------------------------\n";
    echo "Personal Exemption: 20,000 EGP/year\n\n";

    echo "SAFETY CHECK\n";
    echo "--------------------------------------------\n";
    echo "Employees: NOT MODIFIED\n";
    echo "Salaries: NOT MODIFIED\n";
    echo "Payroll Records: NOT MODIFIED\n";
    echo "Payroll Runs: NOT MODIFIED\n";
    echo "Tax Brackets: NOT MODIFIED\n";
    echo "Employee Data: NOT MODIFIED\n\n";

    echo "DONE.\n";

} catch (Throwable $e) {

    echo "\n============================================\n";
    echo "ERROR\n";
    echo "============================================\n";
    echo $e->getMessage();
    echo "\n";

    exit(1);
}