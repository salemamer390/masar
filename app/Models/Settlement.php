<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Settlement extends Model
{
    use BelongsToCompany;

    public const REASONS = ['resignation', 'contract_end', 'dismissal'];
    public const ACTIVE_STATUSES = ['Draft', 'Approved'];

    protected $fillable = [
        'company_id', 'employee_id', 'reason', 'last_working_day', 'status',
        'anchor_month', 'period_start', 'period_end',
        'leave_balance_days', 'leave_payout_mode', 'leave_payout_amount',
        'advance_outstanding', 'advance_recovery_mode', 'advance_recovery_amount', 'advance_recovery_details',
        'gratuity_amount', 'manual_earnings', 'manual_deductions',
        'total_earnings', 'total_deductions', 'net_amount', 'notes',
        'reconciliation', 'payroll_run_id', 'payroll_record_id',
        'created_by', 'approved_by', 'approved_at', 'paid_by', 'paid_at', 'canceled_by', 'canceled_at',
    ];

    protected $casts = [
        'last_working_day' => 'date', 'anchor_month' => 'date', 'period_start' => 'date', 'period_end' => 'date',
        'leave_balance_days' => 'decimal:2', 'leave_payout_amount' => 'decimal:2',
        'advance_outstanding' => 'decimal:2', 'advance_recovery_amount' => 'decimal:2',
        'advance_recovery_details' => 'array',
        'gratuity_amount' => 'decimal:2', 'manual_earnings' => 'decimal:2', 'manual_deductions' => 'decimal:2',
        'total_earnings' => 'decimal:2', 'total_deductions' => 'decimal:2', 'net_amount' => 'decimal:2',
        'approved_at' => 'datetime', 'paid_at' => 'datetime', 'canceled_at' => 'datetime',
    ];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function payrollRun(): BelongsTo { return $this->belongsTo(PayrollRun::class); }
    public function payrollRecord(): BelongsTo { return $this->belongsTo(PayrollRecord::class); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function approver(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function payer(): BelongsTo { return $this->belongsTo(User::class, 'paid_by'); }

    public function getReasonLabelAttribute(): string
    {
        return match ($this->reason) {
            'resignation' => 'استقالة',
            'contract_end' => 'إنهاء عقد',
            'dismissal' => 'فصل',
            default => $this->reason,
        };
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'Draft' => 'مسودة',
            'Approved' => 'معتمدة',
            'Paid' => 'مصروفة ومقفولة',
            'Canceled' => 'ملغاة',
            default => $this->status,
        };
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            'Paid' => 'success',
            'Approved' => 'info',
            'Canceled' => 'secondary',
            default => 'warning',
        };
    }

    /** Decisions the reviewer must make before approval. */
    public function pendingDecisions(): array
    {
        $pending = [];
        if ((float) $this->leave_balance_days > 0
            && ($this->leave_payout_mode === 'pending' || ($this->leave_payout_mode === 'pay' && $this->leave_payout_amount === null))
        ) {
            $pending[] = 'leave_payout';
        }
        if ((float) $this->advance_outstanding > 0 && $this->advance_recovery_mode === 'pending') {
            $pending[] = 'advance_recovery';
        }
        return $pending;
    }
}
