<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    protected $fillable = ['payroll_month', 'status', 'created_by', 'approved_by', 'approved_at', 'locked_by', 'locked_at', 'settings_snapshot'];
    protected $casts = ['payroll_month' => 'date', 'approved_at' => 'datetime', 'locked_at' => 'datetime', 'settings_snapshot' => 'array'];

    public function records(): HasMany { return $this->hasMany(PayrollRecord::class); }
}
