<?php

namespace App\Http\Controllers\Fingerprint;

use App\Enums\FingerprintSyncStatus;
use App\Enums\FingerprintSyncType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fingerprint\FingerprintSyncLogFilterRequest;
use App\Models\FingerprintDevice;
use App\Services\FingerprintDeviceSyncLogService;
use Illuminate\View\View;

class FingerprintDeviceSyncLogController extends Controller
{
    public function __construct(private readonly FingerprintDeviceSyncLogService $service) {}

    public function index(FingerprintSyncLogFilterRequest $request): View
    {
        return view('fingerprint.sync-logs.index', [
            'logs' => $this->service->paginate($request->validated()),
            'devices' => FingerprintDevice::query()->orderBy('name')->get(),
            'statuses' => FingerprintSyncStatus::cases(),
            'syncTypes' => FingerprintSyncType::cases(),
        ]);
    }
}
