<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Concerns\BelongsToCompany;

class ActivityLog extends Model
{
    use BelongsToCompany;
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    protected $fillable = [
        'user_id',
        'action',
        'model_type',
        'model_id',
        'description',
        'module',
        'old_values',
        'new_values',
        'ip_address',
        'company_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(
        string $action,
        string $description,
        ?Model $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $module = null,
    ): void
    {
        self::create([
            'company_id' => $model?->company_id ?? auth()->user()?->company_id,
            // No fallback to a fixed user id: console runs (scheduled period close,
            // imports) have no web user, and attributing their actions to user 1 both
            // misstates the actor and fatals on installs where that id does not exist.
            'user_id' => auth()->id(),
            'action' => $action,
            'model_type' => $model ? get_class($model) : null,
            'model_id' => $model ? $model->getKey() : null,
            'description' => $description,
            'module' => $module ?? explode('_', $action)[0],
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
        ]);
    }

    public function getIconAttribute(): string
    {
        return match ($this->action) {
            'create', 'employee_create' => 'bi-person-plus text-success',
            'leave_approve', 'approve' => 'bi-calendar-check text-primary',
            'salary_update', 'update' => 'bi-cash-stack text-warning',
            default => 'bi-activity text-info',
        };
    }

    public function getIconColorAttribute(): string
    {
        return match ($this->action) {
            'create', 'employee_create' => 'green',
            'leave_approve', 'approve' => 'blue',
            'salary_update', 'update' => 'orange',
            default => 'purple',
        };
    }
}
