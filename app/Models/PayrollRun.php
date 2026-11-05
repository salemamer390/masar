<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToCompany;

class PayrollRun extends Model
{
    use BelongsToCompany;
    protected $fillable = ['company_id', 'payroll_month', 'period_start', 'period_end', 'status', 'is_transition', 'closes_at', 'created_by', 'approved_by', 'approved_at', 'locked_by', 'locked_at', 'settings_snapshot', 'skipped_report'];
    protected $attributes = ['is_transition' => false];
    protected $casts = ['payroll_month' => 'date', 'period_start' => 'date', 'period_end' => 'date', 'closes_at' => 'date', 'is_transition' => 'boolean', 'approved_at' => 'datetime', 'locked_at' => 'datetime', 'settings_snapshot' => 'array', 'skipped_report' => 'array'];

    public function records(): HasMany { return $this->hasMany(PayrollRecord::class); }
}
