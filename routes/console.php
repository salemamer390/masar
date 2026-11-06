<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tazamun:send-contract-alerts --days=30')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('documents:notify-expiring')->dailyAt('08:00')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('tazamun:sync-leave-statuses')->dailyAt('00:05')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('holidays:notify-upcoming')->dailyAt('08:00')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('insurance:notify-registration')->dailyAt('08:00')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('insurance:notify-termination-removal')->dailyAt('08:00')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('leaves:notify-smart')->dailyAt('08:00')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('subscriptions:notify-expiring')->dailyAt('08:00')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('payroll:refresh-drafts')->dailyAt('07:00')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('payroll:refresh-drafts')->dailyAt('23:55')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('summary:capture')->dailyAt('23:55')->timezone('Africa/Cairo')->withoutOverlapping()
    ->when(fn () => now('Africa/Cairo')->isLastOfMonth());
Schedule::command('attendance:archive-periods')->dailyAt('00:10')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('fingerprint:sync-devices')->everyFifteenMinutes()->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('operations:detect-stale-sync')->everyThirtyMinutes()->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::call(fn () => \App\Http\Controllers\AttendanceImportController::purgeStaleImportFiles())->hourly()->name('attendance-import-prune')->withoutOverlapping();
// Every ended period closes automatically once its correction grace (the
// attendance_correction_days company setting, default 3 days) has fully passed:
// attendance, the saved summary, the run and its records freeze together.
Schedule::command('payroll:close-periods')->dailyAt('00:20')->timezone('Africa/Cairo')->withoutOverlapping();
