<?php

namespace App\Services;

use App\Models\RequestType;
use App\Models\User;
use App\Services\Requests\UnifiedRequestSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class UnifiedRequestService
{
    private const PENDING_STATUSES = ['Pending', 'Manager Approved'];

    /**
     * Returns display-ready requests from every active type definition. The type
     * metadata comes only from request_types; sources only adapt their own records.
     */
    public function pending(User $user, ?int $limit = 5, ?string $search = null): Collection
    {
        $requests = collect();

        if ($search !== null && $search !== '') {
            $needle = mb_strtolower($search);
            $requests = $requests->filter(fn (array $item) => str_contains(mb_strtolower($item['title'].' '.$item['subtitle'].' '.$item['type_label']), $needle));
        }

        $requests = $requests->sortByDesc(fn (array $item) => $item['created_at']?->getTimestamp() ?? 0)->values();

        return $limit === null ? $requests : $requests->take($limit)->values();
    }

    public function pendingCount(User $user): int
    {
        return $this->pending($user, null)->count();
    }

    public function canViewAny(User $user): bool
    {
        foreach ($this->definitions() as $definition) {
            $source = $this->sourceFor($definition);
            if ($source?->canView($user)) {
                return true;
            }
        }

        return false;
    }

    public function listingUrl(User $user): string
    {
        // Generic HR requests are the hub for advance/permission/mission/etc.
        if ($user->hasPermission('requests.view')) {
            return route('requests.index');
        }

        foreach ($this->definitions() as $definition) {
            $source = $this->sourceFor($definition);
            if ($source?->canView($user)) {
                return $source->listingUrl($definition);
            }
        }

        return route('dashboard');
    }

    /** @param array<int, string> $statuses */
    private function collect(User $user, array $statuses): Collection
    {
        return $this->definitions()->flatMap(function (RequestType $type) use ($user, $statuses) {
            $source = $this->sourceFor($type);

            return $source ? $source->requests($type, $user, $statuses) : collect();
        });
    }

    private function definitions(): Collection
    {
        return RequestType::query()->active()->orderBy('sort_order')->orderBy('id')->get();
    }

    private function sourceFor(RequestType $type): ?UnifiedRequestSource
    {
        $sourceClass = config('request-sources.'.$type->source_key);
        if (!$sourceClass || !class_exists($sourceClass)) {
            Log::warning('Unknown unified request source.', ['request_type' => $type->code, 'source_key' => $type->source_key]);
            return null;
        }

        $source = app($sourceClass);

        return $source instanceof UnifiedRequestSource ? $source : null;
    }
}
