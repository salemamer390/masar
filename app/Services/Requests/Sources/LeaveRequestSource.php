<?php

namespace App\Services\Requests\Sources;

use App\Models\Leave;
use App\Models\RequestType;
use App\Models\User;
use App\Services\Requests\UnifiedRequestSource;
use Illuminate\Support\Collection;

class LeaveRequestSource implements UnifiedRequestSource
{
    public function canView(User $user): bool
    {
        return $user->hasPermission('leaves.view');
    }

    public function requests(RequestType $type, User $user, array $statuses): Collection
    {
        if (!$this->canView($user)) {
            return collect();
        }

        return Leave::query()
            ->with(['employee', 'leaveType'])
            ->whereIn('status', $statuses)
            ->latest()
            ->get()
            ->map(fn (Leave $leave) => $this->present($leave, $type, $user));
    }

    public function listingUrl(RequestType $type): string
    {
        return route('leaves.index', ['status' => 'Pending']);
    }

    private function present(Leave $leave, RequestType $type, User $user): array
    {
        $status = match ($leave->status) {
            'Manager Approved' => ['بانتظار HR', 'info'],
            'Approved' => ['معتمد', 'success'],
            'Rejected' => ['مرفوض', 'danger'],
            default => ['بانتظار المدير', 'warning'],
        };

        return [
            'id' => $type->code.':'.$leave->getKey(),
            'request_type' => $type->code,
            'type_label' => $type->display_name,
            'icon' => $type->icon,
            'icon_color' => $type->icon_color,
            'icon_background' => $type->icon_background,
            'metadata' => $type->metadata ?? [],
            'title' => trim(($leave->employee?->full_name ?? 'موظف').' - '.$type->display_name),
            'subtitle' => trim(($leave->leaveType?->name ?? '—').' · '.($leave->start_date?->format('d/m/Y') ?? '—').' إلى '.($leave->end_date?->format('d/m/Y') ?? '—')),
            'created_at' => $leave->created_at,
            'status' => $leave->status,
            'status_label' => $status[0],
            'status_class' => $status[1],
            'details_url' => route('leaves.index', ['status' => $leave->status]),
            'listing_url' => $this->listingUrl($type),
            'can_approve' => in_array($leave->status, ['Pending', 'Manager Approved'], true) && $user->hasPermission('leaves.approve'),
            'approve_url' => route('leaves.approve', $leave),
            'reject_url' => route('leaves.reject', $leave),
            'source' => $leave,
        ];
    }
}
