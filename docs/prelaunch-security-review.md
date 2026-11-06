# Website prelaunch review - 7 November 2026

## Fixed in this change
- Block company managers from changing/deleting globally shared master rows. Existing super_admin platform authority is unchanged.
- Store new employee document uploads privately and use authenticated company-scoped preview URLs; retain a legacy public-disk fallback until old files are migrated.
- Company-linked super_admin user management could mutate another company's ordinary account before audit logging rejected the operation. Reject the target before writing; force new users into the acting company; restrict selectable roles to that company.
- Database `exists` rules do not apply Eloquent company scopes. Validate private/global lookup IDs through scoped models before writing employee, bank, document, salary, movement, leave and penalty references. Global and own-company records remain accepted.
- Disable existing web sessions on their next authenticated request when the user account is disabled, including super_admin accounts.
- Rate-limit normal website login, matching the existing owner-login limit.
- Replace vulnerable locked PhpSpreadsheet and Symfony versions with patched compatible releases. Composer audit reported 20 advisories before and none after the update. This is an advisory scan, not proof that every advisory was exploitable here.

## Validation
- Local full-suite results are recorded in the PR closeout, including existing mobile API contracts. No mobile UI work is included.
- Expanded company-isolation regressions cover foreign user disable/edit/delete/create, private lookup rejection, global/own bank acceptance, disabled active sessions and login throttling.
- Tests run against a local isolated fixture database with transaction rollback. No production tests, data edits, migrations, server changes, merges or deployments were performed.

## Explicit exception and remaining launch checks
- LAUNCH BLOCKER: existing documents and employee photos can still reside on public storage. New document privacy does not move those old bytes or protect a static public URL. Plan a backed-up private-storage migration and server-side public-path blocking, then verify anonymous and foreign-company access is denied. Do not delete the public symlink blindly: current photos still rely on it. No live files were moved in this review.
- The old plans/companies page remains accessible to all super_admin accounts as previously requested. This includes company-linked super_admin accounts. The separate owner panel still uses its existing private gate. Restricting the old platform route requires a separate account-policy decision; this PR does not silently change it.
- This is a code/dependency review with regression testing, not a guarantee of zero vulnerabilities or a full independent penetration test.
- Before internet launch, verify the deployed server uses public/ as document root, blocks secrets/source/backups, disables APP_DEBUG, enforces HTTPS/secure cookies, has correct trusted proxy/host settings, applies least-privilege database credentials, and has tested backup/restore and queue/scheduler settings.
- No live server configuration, TLS, backup, network exposure, malware scan, exhaustive role-by-role endpoint test or load/DoS testing was performed.
- External frontend assets remain dependent on CDNs; pin or self-host them and evaluate CSP/SRI before a hardened deployment.
- Re-run Composer audit at deployment time and install from the committed lockfile rather than running an unrestricted update.
