# Website prelaunch review - 7 November 2026

## Fixed in this change
- Block company managers from changing/deleting globally shared master rows. Existing super_admin platform authority is unchanged.
- Store new employee document uploads privately and use authenticated company-scoped preview URLs; retain a legacy public-disk fallback until old files are migrated.
- Store new employee profile photos on the private disk and serve every photo (new and legacy) through an authenticated, company-scoped route; cross-company and guest requests get 404/redirect. The public disk is only a guarded fallback for files not yet migrated.
- Restrict the legacy plans/companies platform screens to the separate owner account (OwnerGate: company-less super admin allowlisted in OWNER_EMAILS). Company-linked super admins keep full management of their own company but lose cross-company management here; the private owner dashboard is unchanged.
- Add `php artisan masar:migrate-private-storage` (`--dry-run` first) to move legacy public documents/photos onto the private disk during the launch rollout, after a verified backup.
- Company-linked super_admin user management could mutate another company's ordinary account before audit logging rejected the operation. Reject the target before writing; force new users into the acting company; restrict selectable roles to that company.
- Database `exists` rules do not apply Eloquent company scopes. Validate private/global lookup IDs through scoped models before writing employee, bank, document, salary, movement, leave and penalty references. Global and own-company records remain accepted.
- Disable existing web sessions on their next authenticated request when the user account is disabled, including super_admin accounts.
- Rate-limit normal website login, matching the existing owner-login limit.
- Replace vulnerable locked PhpSpreadsheet and Symfony versions with patched compatible releases. Composer audit reported 20 advisories before and none after the update. This is an advisory scan, not proof that every advisory was exploitable here.

## Validation
- Local full-suite results are recorded in the PR closeout, including existing mobile API contracts. No mobile UI work is included.
- Expanded company-isolation regressions cover foreign user disable/edit/delete/create, private lookup rejection, global/own bank acceptance, disabled active sessions and login throttling.
- Added regressions for the owner-only platform gate (company-linked super admin denied, owner allowed) and for private photo upload/serving, including the legacy public fallback and cross-company denial.
- Added migration-command regressions: dry-run changes nothing, bytes are verified before the public copy is removed, reruns are idempotent, identical duplicates are deduplicated, conflicting copies are kept and reported, and missing files warn without failing.
- Tests run against a local isolated fixture database with transaction rollback. No production tests, data edits, migrations, server changes, merges or deployments were performed.

## Explicit exception and remaining launch checks
- LAUNCH BLOCKER (rollout prepared, not executed): existing documents and employee photos may still reside on public storage. New uploads are private, and old files are now served only through the guarded routes, but the old static public URLs still work until the rollout runs. At launch: back up storage/app, run `php artisan masar:migrate-private-storage --dry-run` then without it, block/remove the public storage symlink server-side, and verify anonymous and foreign-company access to the old URLs is denied. No live files were moved in this review.
- This is a code/dependency review with regression testing, not a guarantee of zero vulnerabilities or a full independent penetration test.
- Before internet launch, verify the deployed server uses public/ as document root, blocks secrets/source/backups, disables APP_DEBUG, enforces HTTPS/secure cookies, has correct trusted proxy/host settings, applies least-privilege database credentials, and has tested backup/restore and queue/scheduler settings.
- No live server configuration, TLS, backup, network exposure, malware scan, exhaustive role-by-role endpoint test or load/DoS testing was performed.
- External frontend assets remain dependent on CDNs; pin or self-host them and evaluate CSP/SRI before a hardened deployment.
- Re-run Composer audit at deployment time and install from the committed lockfile rather than running an unrestricted update.
