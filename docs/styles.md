# CSS ownership and build

This cleanup preserves existing selectors, declarations, specificity, and cascade order. It does not redesign the UI or replace Bootstrap.

## Shared styles

Edit `resources/styles/`, not the generated `public/app.css`:

1. `01-foundation.css`: first legacy layer (formerly styles.css).
2. `02-components.css`: second legacy layer (formerly ux.css).
3. `03-design-system.css`: final shared layer and overrides.

The names describe ownership, not permission to reorder rules. These legacy layers still overlap; broad selector deduplication needs a separate behavioral and visual review. `app-header.txt` preserves the original bundle header.

Run `php scripts/build-styles.php` to rebuild. Run `php scripts/build-styles.php --check` to fail if source and output diverge. Composer aliases: `composer styles:build` / `composer styles:check`. No Node, npm, Vite, minifier, or production build step is required. Commit sources and output together. The first extraction reproduces the existing app.css byte for byte.

`public/dark-mode.css` remains a separate final head layer. `public/dashboard-reference.css` and `public/payroll/employee-salaries.css` retain their current page ownership. Platform/admin and print/PDF styles are not part of this cleanup.

## Page styles

Static styles from attendance/index, requests/index, payroll/settings, company/account, jobs/index, and roles/index now live in `public/css/pages/`. The numeric suffix preserves each original style block's order. Each link replaces its block in the same Blade position, including body-positioned styles. Do not casually move a body link into `@push('styles')`: that would move it before the dark-mode head layer and could change precedence.

Page links use filemtime versioning. No selector or declaration was changed while extracting them. The empty attendance style block was dropped. Styles containing Blade values, print/PDF styles, and other pages are unchanged.

## Bootstrap

The main layout already loads Bootstrap RTL CSS 5.3.3 once and its JS bundle once. This change does not add a second Bootstrap load, upgrade the version, remove utility classes, or change component markup. Login/owner layouts are independent documents, not duplicate loads inside the main document.

## Legacy copies

Unreferenced public styles.css, design-system.css, responsive.css, and css/styles.css were byte-identical to their existing `_archive/public/` copies; only the public duplicates were removed. The differing public ux.css is preserved as `_archive/public/ux-before-style-organization.css`. They are not sources for the generated bundle. Leave archives as reference, not runtime assets. No platform-admin CSS, script, or view was removed.

## Verification

`StyleAssetsTest` checks bundle/source equality, page asset references, and main-layout load order. Run the complete PHPUnit suite and `php artisan view:cache` before shipping. Visual comparisons must cover light/dark and mobile/desktop; test representative modals and filters as well as initial page views. These checks use local fixtures, not the production database.
