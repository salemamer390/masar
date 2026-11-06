<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/** A single input rule for the visible date and the legacy compatibility name. */
final class EmploymentStartDate
{
    public static function resolve(mixed $start, mixed $legacy = null): ?string
    {
        $start = filled($start) ? Carbon::parse($start)->toDateString() : null;
        $legacy = filled($legacy) ? Carbon::parse($legacy)->toDateString() : null;
        if ($start && $legacy && $start !== $legacy) {
            throw ValidationException::withMessages([
                'contract_start_date' => 'تاريخ بداية العقد وتاريخ التعيين القديم مختلفان. راجع التاريخين قبل الحفظ أو الاستيراد.',
            ]);
        }

        return $start ?? $legacy;
    }
}
