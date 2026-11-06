# One visible employment date

The only editable date is **تاريخ بداية العقد** (`contract_start_date`): the first day of service and pay entitlement. It is not the record creation date, signature date, or the start of a later renewal.

`hire_date` is retained as a compatibility mirror, not another user choice. Existing payroll, salary-start, leave, insurance, tenure, alerts and reporting readers continue to work without a risky wholesale rewrite. `Employee::fill` and the saving guard synchronize Eloquent writes in either direction. Old clients/import headers using hire_date still work. New screens submit contract_start_date. Conflicting supplied values are rejected rather than selecting one silently. Direct query-builder writes bypass model events and must set both columns.

## Deployment (owner-operated; not executed on the user's system)

1. Back up the database and put writes into maintenance mode during deployment.
2. Deploy code in maintenance mode, then run `php artisan employees:check-start-dates` (read-only). Review any conflicting pair with HR. This command does not choose which date is correct and does not change records.
3. Reconcile approved conflicts with an audited data correction before continuing. Existing approved payroll or salary effective dates need their own review; do not rewrite them as a side effect of this change.
4. Run migrations. The consolidation migration checks every row for discrepancies **before any write**, aborts on any conflict, and only copies missing values when the other value exists. It does not modify either non-null conflicting date, existing salary structures, payroll records, or timestamps. Both-null rows remain unknown as before.
5. Verify the employee create/edit/import flows, then leave maintenance mode.

Renewals update the end date only, keeping original service start and tenure. Existing annual renewal/proration rules have not been changed. Existing contract-end input remains editable. Future per-renewal history is separate scope.

Rollback code remains compatible because both physical columns are retained and synchronized. Migration rollback intentionally preserves backfilled dates rather than throwing away information.
