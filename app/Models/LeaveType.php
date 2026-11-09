<?php

namespace App\Models;
use App\Models\Concerns\BelongsToCompanyOrGlobal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use BelongsToCompanyOrGlobal;
    public $timestamps = false;

    protected $fillable = [
        'name',
        'default_days',
        'deducts_annual_balance',
        'is_paid',
        'is_active',
        'policy_code', 'max_days_per_request', 'max_days_per_year', 'min_service_months',
        'max_occurrences_service', 'requires_document', 'eligibility_gender', 'min_employees', 'company_id',
    ];

    protected $casts = [
        'default_days' => 'decimal:2',
        'is_active' => 'boolean',
        'deducts_annual_balance' => 'boolean',
        'is_paid' => 'boolean',
        'max_days_per_request' => 'decimal:2', 'max_days_per_year' => 'decimal:2',
        'requires_document' => 'boolean',
    ];

    /** Company-owned art. 132 templates retain a unique storage code; policy consumers see the statutory kind. */
    public function getPolicyCodeAttribute($value): ?string
    {
        return is_string($value) && preg_match('/^contagious_contact_company_[0-9]+$/', $value) ? 'contagious_contact' : $value;
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class);
    }
}
