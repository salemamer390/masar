# Masar launch readiness checklist

Operational steps for deploying the final version. Nothing here runs itself; each step is manual and verifiable. The command `php artisan masar:verify-launch-readiness` re-checks most items after deployment and prints the remedy for anything failing.

## Merge order

1. PR #35 (security, owner gate, private storage) — no migrations.
2. PR #36 (fresh-install schema and seed fixes) — after merging run an ordinary `php artisan migrate`. Never `migrate:fresh` or `migrate:refresh` on a database that holds data; both wipe it.
3. PR #37 (this checklist, launch-readiness command, pinned CDN assets) — no migrations.

## Server requirements

- PHP 8.2+ with the extensions Composer reports during install (`composer check-platform-reqs`).
- Composer, for `composer install --no-dev --optimize-autoloader` from the committed `composer.lock`.
- MySQL 8 or MariaDB 10.6+. Create the database explicitly as utf8mb4, otherwise Arabic text corrupts:
  `CREATE DATABASE masar CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;`

## Environment (.env)

- `APP_ENV=production`, `APP_DEBUG=false`
- `APP_URL=https://<your-domain>` (https, not http)
- `SESSION_DRIVER=database`, `SESSION_SECURE_COOKIE=true`
- `OWNER_EMAILS=<comma-separated owner emails>` — required for the separate owner account
- `DB_*` pointing at the utf8mb4 database
- `MAIL_MAILER` and credentials for the mail provider (default is `log`, which sends nothing)
- `APP_KEY` via `php artisan key:generate` on first install only

## Scheduled jobs (required)

Payroll auto-close, document/contract alerts, fingerprint sync and other daily jobs run through Laravel's scheduler. One cron entry:

```
* * * * * cd /path/to/masar && php artisan schedule:run >> /dev/null 2>&1
```

## First install

```
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate
php artisan storage:link
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

The first registered company-less super_admin whose email is in `OWNER_EMAILS` becomes the owner account. Company accounts manage their own company only.

## Private storage rollout (existing installs with legacy public files)

New uploads already go to the private disk and are served through authenticated, company-scoped routes. To retire legacy public files:

1. Take a verified backup of `storage/app`.
2. `php artisan masar:migrate-private-storage --dry-run` — review the counts; nothing changes.
3. `php artisan masar:migrate-private-storage` — copies bytes, verifies checksums, then removes public copies. Conflicting files are kept and reported, never overwritten.
4. `php artisan masar:verify-launch-readiness` — must report no files left on the public disk.
5. Block the old public URLs at the web server, then remove the symlink:
   - nginx: `location ^~ /storage/ { return 404; }` (then `rm public/storage`)
   - Apache: remove the `public/storage` symlink; the app's routes keep serving files.

## Static assets and CDN

Bootstrap, Bootstrap Icons and Lucide are pinned to exact versions with Subresource Integrity hashes in the layouts; a tampered CDN response is rejected by the browser. Google Fonts CSS cannot carry an integrity hash (its content varies per browser) and remains a normal external reference. If full self-hosting is preferred later, download those assets into `public/vendor` and swap the URLs.

## Still open before calling the launch done

- The stored-XSS repair/recheck from the earlier security pass remains unverified (see `docs/prelaunch-security-review.md` in PR #35); no clearance is claimed for it.
- HTTPS certificate and web-server hardening are hosting steps.
- Regular verified backups of the database and `storage/app` are an operational requirement, not a code one.
