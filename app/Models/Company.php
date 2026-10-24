<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class Company extends Model
{
    protected $fillable = ['name','slug','email','phone','status'];

    protected static function booted(): void
    {
        // Every new company starts with its own payroll settings, tax brackets and default roles.
        static::created(function (Company $company): void {
            try {
                app(\App\Services\PayrollDefaultsService::class)->ensureForCompany((int) $company->id);
                app(\App\Services\DefaultCompanyRoles::class)->ensureForCompany((int) $company->id, true);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    public function subscriptions(): HasMany { return $this->hasMany(CompanySubscription::class); }
    public function activeSubscription(): ?CompanySubscription { return $this->subscriptions()->whereIn('status',['trial','active'])->latest('id')->first(); }
}
