<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionRenewalRequest extends Model
{
    protected $fillable = [
        'company_id', 'subscription_plan_id', 'requested_by', 'request_type',
        'status', 'amount', 'billing_period', 'note', 'processed_by', 'processed_at',
    ];

    protected $casts = ['amount' => 'decimal:2', 'processed_at' => 'datetime'];

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function plan(): BelongsTo { return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function processor(): BelongsTo { return $this->belongsTo(User::class, 'processed_by'); }
}
