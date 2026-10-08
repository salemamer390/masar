# Payroll Net/Gross-up Fix

## Agreed rule
All agreed salary inputs are Net Salary. Payroll never treats the agreed Net as Gross.

For Net input, PayrollCalculator performs Gross-up against the full insurance/tax engine. The existing Gross-up algorithm was preserved because it produces the approved test result:

- Agreed Net: 22,000.00 EGP
- Gross: 28,118.56 EGP

## Salary Structure allowances
The four allowance fields are editable per employee and blank in the new salary form:
- Transportation
- Meal
- Housing
- Clothing

Their total is limited to 25% of the agreed Net salary. The entered allowance values do not alter the Gross-up result; after Gross is determined, Basic Salary is displayed as Gross minus the entered allowance total.

## Payroll snapshot
Payroll records keep the agreed Net, Gross, insurance/tax results and a calculation snapshot so historical payrolls do not silently change when settings change later.
