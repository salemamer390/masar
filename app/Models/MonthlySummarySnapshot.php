<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class MonthlySummarySnapshot extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'summary_month', 'rows', 'employee_count', 'created_by'];

    protected $casts = ['summary_month' => 'date', 'rows' => 'array'];
}
