<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class CompanySubscription extends Model
{
    protected $fillable = ['company_id','subscription_plan_id','status','starts_at','ends_at','trial_days','feature_overrides'];
    protected $casts = ['starts_at'=>'date','ends_at'=>'date','feature_overrides'=>'array'];
    public function company(): BelongsTo { return $this->belongsTo(Company::class); }
    public function plan(): BelongsTo { return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id'); }
}
