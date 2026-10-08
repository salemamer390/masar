# Tazamun HR — Payroll Engine Implementation

## Current architecture

- `PayrollEngine`: orchestrates the complete monthly calculation.
- `TaxEngine`: category-first annual tax calculation; categories/brackets are database-configured with config fallback for migration safety.
- `InsuranceEngine`: insurance wage, employee/employer contributions, and martyrs settings.
- `OvertimeEngine`: separate day/night/official-holiday/weekly-rest calculations based on Gross.
- `GrossUpEngine`: binary-search reverse payroll calculation.
- `TaxReconciliationService`: year-end actual-tax vs monthly-paid reconciliation.
- `PayrollCalculator`: backward-compatible facade for existing salary pages/controllers.

## Calculation order

1. Employee / salary input
2. Fixed earnings + allowances
3. Variable earnings + profit share
4. Gross-up when input is Net
5. Overtime from Gross
6. Gross Salary
7. Insurance Wage
8. Employee Insurance
9. Annual Gross / Annual Insurance
10. Deductible contributions / exempt profit share / personal exemption
11. Tax Category
12. Category-specific brackets
13. Annual Tax
14. Monthly Tax Under Account
15. Martyrs Fund
16. Other deductions
17. Total deductions
18. Final Net
19. Employer Insurance
20. Employee Cost

## Mandatory reference results

- Net 22,000 => Gross ≈ 28,118.56 with the approved current rounding behavior.
- Insurance Wage = 16,700 for that case.
- Employee Insurance = 1,837.
- Annual Taxable Income = 295,378.72.
- Monthly Payroll Tax = 4,267.50.
- Martyrs Fund = 14.06.
- Final Net ≈ 22,000.
- Taxable 650,000 => Category 2, Annual Tax 141,250, exact monthly calculation 11,770.833333...
- Taxable 1,500,000 => Category 6, Annual Tax 382,500, Monthly Tax 31,875.

## Database configuration

New tables:
- `tax_settings`
- `tax_categories`
- `tax_brackets`
- `insurance_settings`
- `payroll_runs`
- `payroll_items`
- `payroll_deductions`
- `tax_reconciliations`

New modular payroll fields were added to salaries/payroll records for incentives, commission, profit share, overtime components, deductions, overrides, and tax reconciliation values.

## Snapshot

Payroll generation stores a versioned `calculation_snapshot` containing input values, configuration values and calculation output. Historical Payroll records therefore keep the calculation context that existed when the run was generated.

## Verification

All PHP source files pass `php -l` syntax validation.
Automated PHPUnit specifications are included under `tests/Unit`.

Run on the Windows/XAMPP machine:

```text
composer install
php artisan migrate
php artisan optimize:clear
php artisan test
```

Then open Payroll, select the month, and use `تشغيل / إعادة الحساب`.
