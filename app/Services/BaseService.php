<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

abstract class BaseService
{
    protected function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    protected function logAction(string $message, ?Model $subject = null, array $properties = []): void
    {
        logger()->info($message, ['subject_id' => $subject?->getKey(), ...$properties]);
    }
}
