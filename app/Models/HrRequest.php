<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToCompany;

class HrRequest extends Model
{
    use BelongsToCompany;
    protected $fillable = [
        'employee_id',
        'request_type',
        'request_type_id',
        'title',
        'details',
        'payload',
        'status',
        'created_by',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
    ];

    protected $casts = [
        'payload' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function requestType(): BelongsTo
    {
        return $this->belongsTo(RequestType::class, 'request_type_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isAdvance(): bool
    {
        return ($this->requestType?->source_key ?: $this->request_type) === 'advance';
    }

    public function getAdvanceAmountAttribute(): float
    {
        return $this->isAdvance() ? (float) data_get($this->payload, 'amount', 0) : 0.0;
    }

    public function getAdvanceLimitAttribute(): float
    {
        return $this->isAdvance() ? (float) data_get($this->payload, 'limit_amount', 0) : 0.0;
    }

    public function getDisplayValueAttribute(): string
    {
        $source = $this->requestType?->source_key ?: $this->request_type;
        if ($source === 'advance') return number_format((float) data_get($this->payload, 'amount', 0), 2).' ج.م';
        if ($source === 'overtime') return number_format((float) data_get($this->payload, 'hours', 0) * 60, 0).' دقيقة';
        if ($source === 'other' && data_get($this->payload, 'amount') !== null) return number_format((float) data_get($this->payload, 'amount', 0), 2).' ج.م';
        if (data_get($this->payload, 'days') !== null) return number_format((float) data_get($this->payload, 'days', 0), 2).' يوم';
        if (data_get($this->payload, 'hours') !== null) return number_format((float) data_get($this->payload, 'hours', 0), 2).' ساعة';
        return '—';
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isViewer()) {
            return $query;
        }

        if ($user->role?->name === 'employee') {
            return $query->where('employee_id', $user->employee_id ?: 0);
        }

        if ($user->role?->name === 'manager' && $user->employee_id) {
            return $query->where(function (Builder $q) use ($user) {
                $q->where('employee_id', $user->employee_id)
                  ->orWhereHas('employee', fn (Builder $employee) => $employee->where('manager_employee_id', $user->employee_id));
            });
        }

        return $query;
    }

    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'Approved' => 'معتمد',
            'Rejected' => 'مرفوض',
            default => 'معلق',
        };
    }

    public function getStatusBadgeClassAttribute(): string
    {
        return match ($this->status) {
            'Approved' => 'success',
            'Rejected' => 'danger',
            default => 'warning',
        };
    }
}
