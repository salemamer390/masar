<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class SubscriptionPlan extends Model
{
    protected $fillable = ['name','slug','employee_limit','hr_user_limit','price','billing_period','features','is_active','sort_order'];
    protected $casts = ['features'=>'array','is_active'=>'boolean','price'=>'decimal:2'];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CompanySubscription::class, 'subscription_plan_id');
    }
}
