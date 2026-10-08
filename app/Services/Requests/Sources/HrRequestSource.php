<?php

namespace App\Services\Requests\Sources;

use App\Models\HrRequest;
use App\Models\RequestType;
use App\Models\User;
use App\Services\Requests\UnifiedRequestSource;
use Illuminate\Support\Collection;

class HrRequestSource implements UnifiedRequestSource
{
    public function canView(User $user): bool
    {
        return $user->hasPermission('requests.view');
    }

    public function requests(RequestType $type, User $user, array $statuses): Collection
    {
        if (!$this->canView($user)) {
            return collect();
        }

        return HrRequest::query()
            ->with(['employee', 'requestType'])
            ->visibleTo($user)
            ->where(function ($query) use ($type) {
                $query->where('request_type_id', $type->id)
                    ->orWhere('request_type', $type->code);
            })
            ->whereIn('status', $statuses)
            ->latest('id')
            ->get()
            ->map(fn (HrRequest $request) => $this->present($request, $type, $user));
    }

    public function listingUrl(RequestType $type): string
    {
        return route('requests.index', ['type' => $type->code, 'status' => 'Pending']);
    }

    private function present(HrRequest $request, RequestType $type, User $user): array
    {
        $status = match ($request->status) {
            'Approved' => ['معتمد', 'success'],
            'Rejected' => ['مرفوض', 'danger'],
            'Manager Approved' => ['بانتظار HR', 'info'],
            default => ['معلق', 'warning'],
        };

        return [
            'id' => $type->code.':'.$request->getKey(),
            'request_type' => $type->code,
            'type_label' => $type->display_name,
            'icon' => $type->icon,
            'icon_color' => $type->icon_color,
            'icon_background' => $type->icon_background,
            'metadata' => $type->metadata ?? [],
            'title' => $request->employee?->full_name.' - '.$request->title,
            'subtitle' => $request->details ? mb_strimwidth($request->details, 0, 90, '…') : 'طلب موارد بشرية',
            'created_at' => $request->created_at,
            'status' => $request->status,
            'status_label' => $status[0],
            'status_class' => $status[1],
            'details_url' => route('requests.index', ['request' => $request->id]),
            'listing_url' => $this->listingUrl($type),
            'can_approve' => in_array($request->status, ['Pending', 'Manager Approved'], true) && $user->hasPermission('requests.approve'),
            'approve_url' => route('requests.approve', $request),
            'reject_url' => route('requests.reject', $request),
            'source' => $request,
        ];
    }
}
