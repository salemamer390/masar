<?php

namespace App\Notifications;

use App\Models\PayrollRun;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A period's correction grace fully passed and the scheduler froze the run,
 * its attendance and its saved summary. One notification per run per close.
 */
class PayrollPeriodClosedNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly PayrollRun $run) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $month = $this->run->payroll_month?->format('m/Y');

        return [
            'kind' => 'payroll',
            'payroll_run_id' => $this->run->id,
            'company_id' => $this->run->company_id,
            'payroll_month' => $this->run->payroll_month?->toDateString(),
            'is_transition' => (bool) $this->run->is_transition,
            'message' => "تم قفل مسير شهر {$month} تلقائيًا بعد انتهاء مهلة التصحيح. أصبح الحضور والسجلات والمسير لهذه الفترة مجمدة.",
            'url' => route('payroll.index'),
        ];
    }
}
