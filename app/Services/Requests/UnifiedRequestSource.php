<?php

namespace App\Services\Requests;

use App\Models\RequestType;
use App\Models\User;
use Illuminate\Support\Collection;

interface UnifiedRequestSource
{
    public function canView(User $user): bool;

    /** @param array<int, string> $statuses */
    public function requests(RequestType $type, User $user, array $statuses): Collection;

    public function listingUrl(RequestType $type): string;
}
