<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeBankAccount extends Model
{
    protected $fillable = ['employee_id', 'bank_id', 'account_holder_name', 'account_number', 'iban', 'payment_method_id', 'is_primary', 'effective_from', 'effective_to', 'status'];
    protected $casts = ['is_primary' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date'];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function bank(): BelongsTo { return $this->belongsTo(Bank::class); }
    public function paymentMethod(): BelongsTo { return $this->belongsTo(PaymentMethod::class); }
}
