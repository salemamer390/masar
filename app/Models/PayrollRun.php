<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Concerns\BelongsToCompany;

class PayrollRun extends Model
{
    use BelongsToCompany;
    protected $fillable = ['company_id', 'payroll_month', 'status', 'created_by', 'approved_by', 'approved_at', 'locked_by', 'locked_at', 'settings_snapshot', 'skipped_report'];
    protected $casts = ['payroll_month' => 'date', 'approved_at' => 'datetime', 'locked_at' => 'datetime', 'settings_snapshot' => 'array', 'skipped_report' => 'array'];

    public function records(): HasMany { return $this->hasMany(PayrollRecord::class); }
}
