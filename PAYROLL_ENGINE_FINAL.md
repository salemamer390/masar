# Tazamun HR — Payroll Engine Final Logic

- All agreed salaries are stored and calculated as Net.
- Gross-up is solved so the calculated Net equals the agreed Net after statutory deductions.
- Allowance pool: 25% of agreed Net, distributed among transportation, meal, and housing allowances.
- All three allowances are treated as subject to insurance in the Tazamun configuration.
- Insurance wage: `MIN(MAX(Gross / 1.25, 2700), 16700)`.
- Employee insurance: 11% of insurance wage.
- Employer insurance: 18.75% of insurance wage.
- Martyrs Fund: 0.05% of Gross.
- Annual taxable income: Annual Gross - Annual Employee Insurance - Other Tax-Deductible Contributions - Personal Exemption.
- Personal exemption: 20,000 annually.
- Tax category is selected from the configured annual taxable-income thresholds.
- The bracket table is then selected for that category and applied progressively.
- Monthly tax: Annual Tax / 12.
- Fixed deductions are applied after the statutory payroll calculation and reduce the final paid Net.
- Salary Structure is the source for Monthly Payroll, Employee Profile, Insurance display, and payroll exports.

Tax category thresholds and bracket tables are centralized in `config/payroll.php` so they can be changed without rewriting the calculation engine.
