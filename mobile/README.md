# Masar HR mobile

Work in progress. This branch is not a finished application or a public release.

Flutter Android/iOS client in the existing Masar repository. The supplied Flutter prototype is the visual starting point; its demo database, non-validating login and made-up payroll calculations are not used.

## Account rule

Create the company account on the existing website first. Sign into mobile using that same account. There is no mobile signup screen or registration endpoint. Existing roles, direct permissions, company isolation and subscriptions remain authoritative on Laravel.

## Development

- Flutter 3.35.7 / Dart 3.9.2 (baseline used for validation).
- Android SDK and JDK 17 for Android builds. Xcode/macOS and an authorized Apple signing setup are required to validate iOS.
- Backend must include `routes/mobile.php` and the new mobile controllers. The app cannot connect to an older deployment lacking those routes.
- No database migrations or additional authentication-token tables are required. The API uses Laravel's existing encrypted session cookie and CSRF middleware.

```
flutter pub get
flutter analyze
flutter test
flutter run --dart-define=MASAR_BASE_URL=https://YOUR-MASAR-HOST
flutter build apk --debug --target-platform android-arm64
```

An unconfigured development build asks for the server address. Release connections require HTTPS. Cleartext localhost/emulator addresses are allowed only in debug builds. Web builds are a development preview and must be served from the same origin as the backend for browser session cookies. Passwords are never stored; native session cookies use platform secure storage. HR data is not cached to SharedPreferences.

## Current code coverage

- Real sign-in, session restore/logout, validation/error states and expired-session handling.
- Permission-driven navigation, server pagination/search, Arabic RTL and Cairo typography.
- Dashboard projections from existing server use cases.
- Employee directory/profile projection and basic create/edit form. No invented hard-delete employee action.
- Administrative attendance entry/clock actions through the existing controllers and period guards. Missing punches are never fabricated. This is not employee GPS attendance.
- Administrative leave entry/edit/rejection with server balances and working-day rules. These entries take effect immediately, matching the existing system.
- Penalty forms and document upload, using existing controllers.
- HR request forms based on source type, with explicit advance confirmations and duplicate-warning display.
- Explicit payroll generation batches, saved-run reconciliation, approval, locking, final close, input changes and recorded pay actions, all delegated to existing controllers. Confirmation is required for writes. Export and payslip/document sharing use the authenticated session.
- Stored payroll read model. Opening the screen does not create or recalculate a payroll run.
- Reports, monthly summary and notifications read models.

## Not complete / next validation work

- Exercise Android/iOS native sharing and file opening on real devices. Backend XLSX/CSV export and document download are covered by local API tests; the native share sheet is not device-tested.
- Exercise every create/update/upload/request subtype end to end against a local test backend, including stale roles, locked periods, permissions and validation paths. The initial automated tests are not complete workflow coverage.
- Add broader widget tests and inspect remaining detail/form states at narrow widths and larger text sizes.
- Android ARM64 debug APK compilation and debug-signature verification succeeded. Installation, real-device behavior and production connectivity still need testing. This is not a public signed release.
- Build and test on iOS using macOS/Xcode. No iOS installable package, signing or TestFlight upload is claimed.
- Configure a final package identifier, production endpoint, app icons, release signing and distribution only after owner setup. Generated identifiers and debug keys are not a public release identity.
- Prepare a signed release and website download links later. No binary/download page is deployed by this branch.

GPS employee check-in/out is a later requirement, not implemented here. No location, face-recognition or biometric permission is requested. GPS timing/geofences are not decided policies.

## Verification checkpoint

- Dart source/test analysis: no issues.
- 14 Flutter tests passed, including all forms at 320px / 1.4x text and reviewed payroll input submission.
- 13 Laravel mobile API tests / 131 assertions cover existing-account login, no signup, CSRF, company isolation, viewer restrictions, account/subscription checks, employee date synchronization, documents, leave reversal, penalties, nine HR request types, payroll generation/inputs/approval/lock/export and no payroll creation on read.
- Full Laravel suite: 388 tests / 1960 assertions passed with 4 existing PHPUnit deprecations.
- Android ARM64 debug APK compiled successfully after installing required Android build tools.
- Web preview compiled and dashboard/directory/attendance/leave/navigation screens were visually inspected using test fixtures. This does not establish real-device behavior or production connectivity.

The payroll report screen uses the existing approved/locked report data. Its Excel export follows the existing web export, including drafts; the app states this difference. An explicit payroll.delete grant can delete a locked run, matching the existing server policy, not a new mobile exception.

Tests and coverage will be expanded on this same branch. Do not merge/deploy as a finished mobile product yet.
