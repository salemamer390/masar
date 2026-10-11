<?php

namespace App\Models;
use App\Models\Concerns\BelongsToCompanyOrGlobal;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    use BelongsToCompanyOrGlobal;
    protected $fillable = ['name', 'code', 'is_active', 'company_id'];
    protected $casts = ['is_active' => 'boolean'];

    public function employeeBankAccounts(): HasMany
    {
        return $this->hasMany(EmployeeBankAccount::class);
    }
}
