# Mobile API checkpoint

The `/mobile/v1` route group is loaded by `routes/web.php`, so it shares Laravel's session encryption and CSRF protection. It is not a replacement for existing web routes. Login is rate-limited. Mutating business routes delegate to the current controllers and their validation, tenant guards, audit logs and payroll-period checks. New reads project current use cases into compact JSON. Payroll reads query stored records only.

Clients begin with `GET /mobile/v1/session` to receive CSRF and current identity/permissions. Send the session cookie and `X-CSRF-TOKEN` on subsequent writes. `POST /mobile/v1/login` uses existing credentials; `POST /mobile/v1/logout` invalidates the session. No mobile registration route exists. HR data routes additionally require authentication, an active account/company subscription and the same module permission as their web counterpart.

No server deployment, migration, production account creation, real attendance mutation or production payroll operation was performed while developing this change. See `mobile/README.md` for known unfinished coverage.


## Payroll and files

Payroll generation remains an explicit user action: start and then process batches of 25. No write is retried automatically. A failed or interrupted batch requires checking the server state before resuming. Approval uses the existing reconciliation report and asks for an explicit confirmation when employees lack records. The existing controller owns approval, locking, final close, input recalculation, recorded pay and delete rules; recorded pay is not a bank transfer. An existing payroll.delete grant can delete a locked run, matching the website.

Legacy payroll redirect/flash responses are adapted to JSON only within the mobile middleware. Validation flash errors become 422. Only a current controller toast can establish a successful redirect outcome; unexplained redirects are 502, never assumed success. Previous flash errors/toasts are cleared before a write.

Payroll XLSX, report CSV/XLSX, payslip PDF and document-file routes retain existing permissions and company isolation. Native sharing uses authenticated bytes, never a public storage URL. HTML/JSON masquerading as a successful file is rejected. Files over 25 MB must use the website export. The native share sheet still requires device testing. The payroll report display follows approved/locked records, while its existing web Excel export also includes drafts; this difference is stated in the mobile UI.

The single employee start input is contract_start_date, labelled تاريخ بداية العقد. Create and edit delegate to EmployeeController normalization, which synchronizes legacy hire_date to the same value. No independent mobile hire-date input is introduced.
