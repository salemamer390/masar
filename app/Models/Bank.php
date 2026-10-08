<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    protected $fillable = ['name', 'code', 'is_active'];
    protected $casts = ['is_active' => 'boolean'];

    public function employeeBankAccounts(): HasMany
    {
        return $this->hasMany(EmployeeBankAccount::class);
    }
}
