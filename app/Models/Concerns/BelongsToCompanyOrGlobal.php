<?php
namespace App\Models\Concerns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
trait BelongsToCompanyOrGlobal
{
    protected static function bootBelongsToCompanyOrGlobal(): void
    {
        static::addGlobalScope('company_or_global', function (Builder $builder): void {
            $user = Auth::user();
            if ($user && !$user->isSuperAdmin() && $user->company_id) {
                $table = $builder->getModel()->getTable();
                $builder->where(function (Builder $query) use ($table, $user): void {
                    $query->whereNull($table.'.company_id')->orWhere($table.'.company_id', $user->company_id);
                });
            }
        });
        static::creating(function ($model): void {
            if (!$model->company_id && Auth::check() && !Auth::user()->isSuperAdmin()) $model->company_id = Auth::user()->company_id;
        });
    }
}
