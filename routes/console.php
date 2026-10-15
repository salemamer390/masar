<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tazamun:send-contract-alerts --days=30')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('documents:notify-expiring')->dailyAt('08:00')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('tazamun:sync-leave-statuses')->dailyAt('00:05')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('holidays:notify-upcoming')->dailyAt('08:00')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('insurance:notify-registration')->dailyAt('08:00')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('insurance:notify-termination-removal')->dailyAt('08:00')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('subscriptions:notify-expiring')->dailyAt('08:00')->timezone('Africa/Cairo')->withoutOverlapping();
Schedule::command('summary:capture')->dailyAt('23:55')->timezone('Africa/Cairo')->withoutOverlapping()
    ->when(fn () => now('Africa/Cairo')->isLastOfMonth());
