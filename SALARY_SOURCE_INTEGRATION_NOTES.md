# Salary Source Integration — 2026-10-08

- Added `employees.gross_salary` and `employees.net_salary`.
- Added backfill from the latest salary structure for existing employees.
- Employee create/edit supports Gross or Net input; the other value is calculated through the existing PayrollCalculator.
- Gross is persisted as the employee salary source.
- Creating/updating an employee salary source creates/synchronizes the active Salary Structure from employee gross.
- Salary Structure UI reads Gross from `employees.gross_salary` and does not allow manual re-entry of the source amount.
- SalaryController enforces the employee gross as the salary structure input.
- `app/Services/PayrollCalculator.php` was not modified.
