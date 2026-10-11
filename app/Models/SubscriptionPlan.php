<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class SubscriptionPlan extends Model
{
    protected $fillable = ['name','slug','employee_limit','price','billing_period','features','is_active','sort_order'];
    protected $casts = ['features'=>'array','is_active'=>'boolean','price'=>'decimal:2'];
}
