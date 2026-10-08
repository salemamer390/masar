<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tazamun:send-contract-alerts --days=30')->dailyAt('08:00')->withoutOverlapping();
Schedule::command('documents:notify-expiring')->dailyAt('08:00')->timezone('Africa/Cairo')->withoutOverlapping();
