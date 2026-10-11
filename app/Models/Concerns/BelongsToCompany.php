<?php
namespace App\Models\Concerns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use App\Models\Employee;
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $builder): void {
            $user = Auth::user();
            if ($user && !$user->isSuperAdmin() && $user->company_id) $builder->where($builder->getModel()->getTable().'.company_id', $user->company_id);
        });
        static::creating(function ($model): void {
            if ($model->company_id) return;
            if (isset($model->employee_id)) $model->company_id = Employee::withoutGlobalScopes()->whereKey($model->employee_id)->value('company_id');
            $model->company_id ??= Auth::user()?->company_id;
        });
    }
}
