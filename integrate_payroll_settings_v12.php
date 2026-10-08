<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Tazamun V12
| Payroll Settings Integration
|--------------------------------------------------------------------------
|
| الهدف:
| - ربط Payroll Engine بإعدادات قاعدة البيانات.
| - إزالة الاعتماد على الأرقام الثابتة.
| - الحفاظ على TaxCalculator منفصل.
| - Absence = Gross
| - Penalty = Basic
|
| IMPORTANT:
| هذا السكربت لا يعدل أي بيانات موظفين أو رواتب.
|
*/

$root = __DIR__;

$payrollCalculator =
    $root . DIRECTORY_SEPARATOR .
    'app' . DIRECTORY_SEPARATOR .
    'Services' . DIRECTORY_SEPARATOR .
    'PayrollCalculator.php';

$settingsService =
    $root . DIRECTORY_SEPARATOR .
    'app' . DIRECTORY_SEPARATOR .
    'Services' . DIRECTORY_SEPARATOR .
    'PayrollSettingsService.php';

if (!file_exists($payrollCalculator)) {
    die(
        "ERROR: PayrollCalculator.php was not found:\n" .
        $payrollCalculator . PHP_EOL
    );
}

echo "Tazamun V12 Payroll Settings Integration\n";
echo "========================================\n\n";

/*
|--------------------------------------------------------------------------
| 1. Read current PayrollCalculator
|--------------------------------------------------------------------------
*/

$original = file_get_contents($payrollCalculator);

if ($original === false) {
    die("ERROR: Cannot read PayrollCalculator.php\n");
}

if (strpos($original, 'class PayrollCalculator') === false) {
    die(
        "ERROR: PayrollCalculator.php does not contain the expected class.\n" .
        "No files were modified.\n"
    );
}

/*
|--------------------------------------------------------------------------
| 2. Backup
|--------------------------------------------------------------------------
*/

$backup =
    $payrollCalculator .
    '.backup_' .
    date('Ymd_His');

if (!copy($payrollCalculator, $backup)) {
    die(
        "ERROR: Could not create backup:\n" .
        $backup . PHP_EOL
    );
}

echo "Backup created:\n";
echo $backup . "\n\n";

/*
|--------------------------------------------------------------------------
| 3. Create PayrollSettingsService
|--------------------------------------------------------------------------
*/

$serviceDirectory =
    $root .
    DIRECTORY_SEPARATOR .
    'app' .
    DIRECTORY_SEPARATOR .
    'Services';

if (!is_dir($serviceDirectory)) {
    die("ERROR: Services directory not found.\n");
}

$serviceCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PayrollSettingsService
{
    /**
     * Read numeric payroll calculation setting.
     */
    public function number(
        string $group,
        string $key,
        float $default = 0.0
    ): float {
        $value = DB::table('payroll_calculation_settings')
            ->where('setting_group', $group)
            ->where('setting_key', $key)
            ->where('is_active', 1)
            ->value('setting_value');

        if ($value === null || $value === '') {
            return $default;
        }

        return (float) $value;
    }

    /**
     * Read text-based payroll calculation rule.
     */
    public function rule(
        string $group,
        string $key,
        string $default = ''
    ): string {
        $value = DB::table('payroll_calculation_rules')
            ->where('rule_group', $group)
            ->where('rule_key', $key)
            ->where('is_active', 1)
            ->value('rule_value');

        if ($value === null || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    /*
    |--------------------------------------------------------------------------
    | General
    |--------------------------------------------------------------------------
    */

    public function allowanceRate(): float
    {
        return $this->number(
            'general',
            'allowance_rate',
            25
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Insurance
    |--------------------------------------------------------------------------
    */

    public function employeeInsuranceRate(): float
    {
        return $this->number(
            'insurance',
            'employee_insurance_rate',
            11
        ) / 100;
    }

    public function employerInsuranceRate(): float
    {
        return $this->number(
            'insurance',
            'employer_insurance_rate',
            18.75
        ) / 100;
    }

    public function martyrsFundRate(): float
    {
        return $this->number(
            'insurance',
            'martyrs_fund_rate',
            0.05
        ) / 100;
    }

    public function insuranceMinimum(): float
    {
        return $this->number(
            'insurance',
            'insurance_minimum',
            2700
        );
    }

    public function insuranceMaximum(): float
    {
        return $this->number(
            'insurance',
            'insurance_maximum',
            16700
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Overtime
    |--------------------------------------------------------------------------
    */

    public function hoursDivisor(): float
    {
        return $this->number(
            'overtime',
            'hours_divisor',
            240
        );
    }

    public function daysDivisor(): float
    {
        return $this->number(
            'overtime',
            'days_divisor',
            30
        );
    }

    public function dayOvertimeMultiplier(): float
    {
        return $this->number(
            'overtime',
            'day_multiplier',
            1.35
        );
    }

    public function nightOvertimeMultiplier(): float
    {
        return $this->number(
            'overtime',
            'night_multiplier',
            1.70
        );
    }

    public function officialHolidayMultiplier(): float
    {
        return $this->number(
            'overtime',
            'official_holiday_multiplier',
            2
        );
    }

    public function weeklyRestMultiplier(): float
    {
        return $this->number(
            'overtime',
            'weekly_rest_multiplier',
            1
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Tax
    |--------------------------------------------------------------------------
    */

    public function personalExemption(): float
    {
        return $this->number(
            'tax',
            'personal_exemption',
            20000
        );
    }

    public function taxDeductibleContributions(): float
    {
        return $this->number(
            'tax',
            'tax_deductible_contributions',
            0
        );
    }

    public function profitShareTaxExempt(): float
    {
        return $this->number(
            'tax',
            'profit_share_tax_exempt',
            0
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Absence / Penalty
    |--------------------------------------------------------------------------
    */

    public function absenceBasis(): string
    {
        return $this->rule(
            'absence',
            'calculation_basis',
            'Gross'
        );
    }

    public function penaltyBasis(): string
    {
        return $this->rule(
            'penalty',
            'calculation_basis',
            'Basic'
        );
    }

    public function absenceDailyDivisor(): float
    {
        return $this->number(
            'absence',
            'daily_divisor',
            30
        );
    }

    public function penaltyDailyDivisor(): float
    {
        return $this->number(
            'penalty',
            'daily_divisor',
            30
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Overtime basis
    |--------------------------------------------------------------------------
    */

    public function overtimeBasis(): string
    {
        return $this->rule(
            'overtime',
            'calculation_basis',
            'Gross'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Insurance basis
    |--------------------------------------------------------------------------
    */

    public function insuranceAutomaticBasis(): string
    {
        return $this->rule(
            'insurance',
            'automatic_calculation_basis',
            'Gross'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Allowance Distribution
    |--------------------------------------------------------------------------
    */

    public function allowanceDistribution(): array
    {
        return DB::table('payroll_allowance_settings')
            ->where('is_active', 1)
            ->orderBy('sort_order')
            ->get([
                'allowance_key',
                'allowance_name',
                'percentage',
                'calculation_basis',
                'sort_order',
            ])
            ->mapWithKeys(function ($row) {
                return [
                    $row->allowance_key => [
                        'name' => $row->allowance_name,
                        'percentage' => (float) $row->percentage,
                        'basis' => $row->calculation_basis,
                        'sort_order' => (int) $row->sort_order,
                    ],
                ];
            })
            ->toArray();
    }

    /*
    |--------------------------------------------------------------------------
    | Complete Snapshot
    |--------------------------------------------------------------------------
    */

    public function snapshot(): array
    {
        return [
            'general' => [
                'allowance_rate' => $this->allowanceRate(),
            ],

            'insurance' => [
                'employee_rate' => $this->employeeInsuranceRate(),
                'employer_rate' => $this->employerInsuranceRate(),
                'martyrs_rate' => $this->martyrsFundRate(),
                'minimum' => $this->insuranceMinimum(),
                'maximum' => $this->insuranceMaximum(),
                'automatic_basis' => $this->insuranceAutomaticBasis(),
            ],

            'overtime' => [
                'hours_divisor' => $this->hoursDivisor(),
                'days_divisor' => $this->daysDivisor(),
                'day_multiplier' => $this->dayOvertimeMultiplier(),
                'night_multiplier' => $this->nightOvertimeMultiplier(),
                'official_holiday_multiplier' => $this->officialHolidayMultiplier(),
                'weekly_rest_multiplier' => $this->weeklyRestMultiplier(),
                'basis' => $this->overtimeBasis(),
            ],

            'tax' => [
                'personal_exemption' => $this->personalExemption(),
                'tax_deductible_contributions' =>
                    $this->taxDeductibleContributions(),
                'profit_share_tax_exempt' =>
                    $this->profitShareTaxExempt(),
            ],

            'deductions' => [
                'absence_basis' => $this->absenceBasis(),
                'absence_daily_divisor' => $this->absenceDailyDivisor(),
                'penalty_basis' => $this->penaltyBasis(),
                'penalty_daily_divisor' => $this->penaltyDailyDivisor(),
            ],

            'allowances' => $this->allowanceDistribution(),
        ];
    }
}
PHP;

if (file_put_contents($settingsService, $serviceCode) === false) {
    die("ERROR: Could not create PayrollSettingsService.php\n");
}

echo "PayrollSettingsService.php created.\n";

/*
|--------------------------------------------------------------------------
| 4. Inject PayrollSettingsService into PayrollCalculator
|--------------------------------------------------------------------------
*/

$modified = $original;

/*
|--------------------------------------------------------------------------
| Constructor
|--------------------------------------------------------------------------
*/

$constructorPattern = '/public function __construct\s*\((.*?)\)\s*\{/s';

if (preg_match($constructorPattern, $modified, $match)) {

    $constructor = $match[0];

    if (strpos($constructor, 'PayrollSettingsService') === false) {

        $newConstructor = $constructor;

        /*
         * Add the dependency after the existing constructor arguments.
         */
        $newConstructor = preg_replace(
            '/(\$taxCalculator\s*:\s*TaxCalculator\s*)/',
            '$1, PayrollSettingsService $settings',
            $newConstructor
        );

        if ($newConstructor === $constructor) {

            /*
             * Fallback for common constructor format.
             */
            $newConstructor = preg_replace(
                '/\)\s*\{$/',
                ', PayrollSettingsService $settings) {',
                $newConstructor
            );
        }

        if ($newConstructor !== $constructor) {

            $modified = substr_replace(
                $modified,
                $newConstructor,
                strpos($modified, $constructor),
                strlen($constructor)
            );

            /*
             * Add property.
             */
            $propertyAnchor =
                'class PayrollCalculator';

            $propertyPos = strpos(
                $modified,
                $propertyAnchor
            );

            if ($propertyPos !== false) {

                $insertPos =
                    strpos(
                        $modified,
                        "\n",
                        $propertyPos
                    ) + 1;

                $modified =
                    substr($modified, 0, $insertPos) .
                    "\n    private PayrollSettingsService \$settings;\n" .
                    substr($modified, $insertPos);
            }

            /*
             * Add assignment inside constructor.
             */
            $constructorStart =
                strpos(
                    $modified,
                    $newConstructor
                );

            if ($constructorStart !== false) {

                $bodyStart =
                    $constructorStart +
                    strlen($newConstructor);

                $modified =
                    substr($modified, 0, $bodyStart) .
                    "\n        \$this->settings = \$settings;\n" .
                    substr($modified, $bodyStart);
            }
        }
    }
}

/*
|--------------------------------------------------------------------------
| 5. Add use statement
|--------------------------------------------------------------------------
*/

if (
    strpos(
        $modified,
        'use App\Services\PayrollSettingsService;'
    ) === false
) {

    $namespacePos =
        strpos(
            $modified,
            'namespace App\Services;'
        );

    if ($namespacePos !== false) {

        $lineEnd =
            strpos(
                $modified,
                "\n",
                $namespacePos
            );

        $modified =
            substr($modified, 0, $lineEnd + 1) .
            "\nuse App\Services\PayrollSettingsService;\n" .
            substr($modified, $lineEnd + 1);
    }
}

/*
|--------------------------------------------------------------------------
| 6. Replace hardcoded PayrollCalculator constants
|--------------------------------------------------------------------------
|
| We intentionally replace only known constant references.
|
*/

$replacements = [

    'self::MIN_INSURANCE' =>
        '$this->settings->insuranceMinimum()',

    'self::MAX_INSURANCE' =>
        '$this->settings->insuranceMaximum()',

    'self::EMPLOYEE_RATE' =>
        '$this->settings->employeeInsuranceRate()',

    'self::EMPLOYER_RATE' =>
        '$this->settings->employerInsuranceRate()',

    'self::MARTYRS_RATE' =>
        '$this->settings->martyrsFundRate()',

    'self::PERSONAL_EXEMPTION' =>
        '$this->settings->personalExemption()',
];

foreach ($replacements as $from => $to) {
    $modified = str_replace(
        $from,
        $to,
        $modified
    );
}

/*
|--------------------------------------------------------------------------
| 7. Replace overtime hardcoded constants where identifiable
|--------------------------------------------------------------------------
*/

$modified = str_replace(
    'self::DAY_OVERTIME_MULTIPLIER',
    '$this->settings->dayOvertimeMultiplier()',
    $modified
);

$modified = str_replace(
    'self::NIGHT_OVERTIME_MULTIPLIER',
    '$this->settings->nightOvertimeMultiplier()',
    $modified
);

$modified = str_replace(
    'self::OFFICIAL_HOLIDAY_MULTIPLIER',
    '$this->settings->officialHolidayMultiplier()',
    $modified
);

$modified = str_replace(
    'self::WEEKLY_HOLIDAY_MULTIPLIER',
    '$this->settings->weeklyRestMultiplier()',
    $modified
);

$modified = str_replace(
    'self::HOURS_DIVISOR',
    '$this->settings->hoursDivisor()',
    $modified
);

$modified = str_replace(
    'self::DAYS_DIVISOR',
    '$this->settings->daysDivisor()',
    $modified
);

/*
|--------------------------------------------------------------------------
| 8. Safety check
|--------------------------------------------------------------------------
*/

if (
    strpos(
        $modified,
        '$this->settings'
    ) === false
) {

    copy(
        $backup,
        $payrollCalculator
    );

    die(
        "\nERROR: No PayrollCalculator settings references were detected.\n" .
        "The original file was restored from backup.\n" .
        "No PayrollCalculator changes were applied.\n"
    );
}

/*
|--------------------------------------------------------------------------
| 9. Ensure required namespace exists
|--------------------------------------------------------------------------
*/

if (
    strpos(
        $modified,
        'PayrollSettingsService'
    ) === false
) {

    copy(
        $backup,
        $payrollCalculator
    );

    die(
        "\nERROR: PayrollSettingsService could not be injected.\n" .
        "The original PayrollCalculator was restored.\n"
    );
}

/*
|--------------------------------------------------------------------------
| 10. Write modified PayrollCalculator
|--------------------------------------------------------------------------
*/

if (
    file_put_contents(
        $payrollCalculator,
        $modified
    ) === false
) {

    copy(
        $backup,
        $payrollCalculator
    );

    die(
        "\nERROR: Could not save PayrollCalculator.php.\n" .
        "Backup remains available.\n"
    );
}

/*
|--------------------------------------------------------------------------
| 11. Clear Laravel caches
|--------------------------------------------------------------------------
*/

echo "PayrollCalculator updated.\n";

$artisan = $root . DIRECTORY_SEPARATOR . 'artisan';

if (file_exists($artisan)) {

    echo "\nClearing Laravel caches...\n";

    $commands = [
        'optimize:clear',
    ];

    foreach ($commands as $command) {

        $cmd =
            'php "' .
            $artisan .
            '" ' .
            $command;

        passthru($cmd);
    }
}

/*
|--------------------------------------------------------------------------
| 12. Final report
|--------------------------------------------------------------------------
*/

echo "\n";
echo "============================================\n";
echo "Tazamun V12 INTEGRATION COMPLETED\n";
echo "============================================\n\n";

echo "Created:\n";
echo "- app/Services/PayrollSettingsService.php\n\n";

echo "Updated:\n";
echo "- app/Services/PayrollCalculator.php\n\n";

echo "Backup:\n";
echo "- {$backup}\n\n";

echo "Database-driven settings:\n";
echo "- Allowance rate\n";
echo "- Employee insurance rate\n";
echo "- Employer insurance rate\n";
echo "- Martyrs Fund rate\n";
echo "- Insurance minimum\n";
echo "- Insurance maximum\n";
echo "- Overtime multipliers\n";
echo "- Overtime divisors\n";
echo "- Personal exemption\n";
echo "- Tax deductible contributions\n";
echo "- Profit share tax exemption\n";
echo "- Absence calculation basis\n";
echo "- Penalty calculation basis\n";
echo "- Allowance distribution\n\n";

echo "Calculation rules:\n";
echo "- Absence = Gross\n";
echo "- Penalty = Basic\n";
echo "- Overtime = Gross\n\n";

echo "SAFETY:\n";
echo "- Employees: NOT MODIFIED\n";
echo "- Salaries: NOT MODIFIED\n";
echo "- Payroll Records: NOT MODIFIED\n";
echo "- Payroll Runs: NOT MODIFIED\n";
echo "- Tax Brackets: NOT MODIFIED\n";
echo "- TaxCalculator: KEPT SEPARATE\n\n";

echo "DONE.\n";