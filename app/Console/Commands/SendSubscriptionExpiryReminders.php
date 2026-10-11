<?php

namespace App\Console\Commands;

use App\Models\CompanySubscription;
use App\Models\User;
use App\Notifications\SubscriptionExpiryReminderNotification;
use Illuminate\Console\Command;

class SendSubscriptionExpiryReminders extends Command
{
    protected $signature = 'subscriptions:notify-expiring';
    protected $description = 'Notify company HR managers and platform administrators before subscription expiry.';

    public function handle(): int
    {
        $sent = 0;
        $admins = User::with('role')->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => $user->isSuperAdmin());

        foreach ([30, 7, 3, 0] as $days) {
            $subscriptions = CompanySubscription::with('company')
                ->whereIn('status', ['trial', 'active'])
                ->whereDate('ends_at', today()->addDays($days))
                ->get();

            foreach ($subscriptions as $subscription) {
                $companyUsers = User::with('role')
                    ->where('company_id', $subscription->company_id)
                    ->where('is_active', true)
                    ->get()
                    ->filter(fn (User $user) => $user->role?->name === 'hr_manager');

                foreach ($admins->merge($companyUsers) as $recipient) {
                    $exists = $recipient->notifications()
                        ->where('type', SubscriptionExpiryReminderNotification::class)
                        ->where('data->subscription_id', $subscription->id)
                        ->where('data->days_remaining', $days)
                        ->exists();
                    if (!$exists) {
                        $recipient->notify(new SubscriptionExpiryReminderNotification($subscription, $days));
                        $sent++;
                    }
                }
            }
        }

        $this->info("Subscription expiry reminders sent: {$sent}.");
        return self::SUCCESS;
    }
}
