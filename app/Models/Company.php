<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Company extends Model
{
    protected $fillable = ['name','slug','email','phone','status'];
    public function subscriptions(): HasMany { return $this->hasMany(CompanySubscription::class); }
    public function activeSubscription(): ?CompanySubscription { return $this->subscriptions()->whereIn('status',['trial','active'])->latest('id')->first(); }
}
