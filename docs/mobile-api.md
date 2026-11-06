# Mobile API checkpoint

The `/mobile/v1` route group is loaded by `routes/web.php`, so it shares Laravel's session encryption and CSRF protection. It is not a replacement for existing web routes. Login is rate-limited. Mutating business routes delegate to the current controllers and their validation, tenant guards, audit logs and payroll-period checks. New reads project current use cases into compact JSON. Payroll reads query stored records only.

Clients begin with `GET /mobile/v1/session` to receive CSRF and current identity/permissions. Send the session cookie and `X-CSRF-TOKEN` on subsequent writes. `POST /mobile/v1/login` uses existing credentials; `POST /mobile/v1/logout` invalidates the session. No mobile registration route exists. HR data routes additionally require authentication, an active account/company subscription and the same module permission as their web counterpart.

No server deployment, migration, production account creation, real attendance mutation or production payroll operation was performed while developing this change. See `mobile/README.md` for known unfinished coverage.
