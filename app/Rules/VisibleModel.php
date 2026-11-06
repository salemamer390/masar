<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/** Unlike database exists rules, Eloquent applies company/global visibility. */
class VisibleModel implements ValidationRule
{
    /** @param class-string<Model> $model */
    public function __construct(private string $model) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!$this->model::query()->whereKey($value)->exists()) {
            $fail('validation.exists')->translate();
        }
    }
}
