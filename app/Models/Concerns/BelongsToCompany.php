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
        static::saving(function ($model): void {
            $actor = Auth::user();
            if (isset($model->employee_id) && $model->employee_id) {
                $employeeCompanyId = Employee::withoutGlobalScopes()->whereKey($model->employee_id)->value('company_id');
                if (!$employeeCompanyId) {
                    throw new \Illuminate\Database\Eloquent\ModelNotFoundException("Employee {$model->employee_id} was not found.");
                }
                if ($model->company_id && (int) $model->company_id !== (int) $employeeCompanyId) {
                    throw new \LogicException('A company-owned record cannot reference an employee from another company.');
                }
                $model->company_id = $employeeCompanyId;
            }

            $model->company_id ??= $actor?->company_id;
            if ($actor && !$actor->isSuperAdmin() && $actor->company_id && (int) $model->company_id !== (int) $actor->company_id) {
                throw new \Illuminate\Auth\Access\AuthorizationException('The record does not belong to the current company.');
            }
        });
    }
}
