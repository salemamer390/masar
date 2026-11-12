<?php

namespace App\Http\Controllers\Fingerprint;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fingerprint\FingerprintDeviceFilterRequest;
use App\Http\Requests\Fingerprint\FingerprintDeviceRequest;
use App\Enums\FingerprintSyncStatus;
use App\Jobs\SyncDueDevicesJob;
use App\Models\FingerprintDevice;
use App\Services\DeviceConnectionService;
use App\Services\FingerprintAttendanceSyncService;
use App\Services\FingerprintDeviceService;
use App\Services\FingerprintPushTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class FingerprintDeviceController extends Controller
{
    public function __construct(
        private readonly FingerprintDeviceService $service,
        private readonly DeviceConnectionService $connectionService,
        private readonly FingerprintAttendanceSyncService $syncService,
        private readonly FingerprintPushTokenService $pushTokens
    ) {}

    public function index(FingerprintDeviceFilterRequest $request): View
    {
        return view('fingerprint.devices.index', [
            'devices' => $this->service->paginate($request->validated()),
            ...$this->service->lookups(),
        ]);
    }

    public function create(): View
    {
        return view('fingerprint.devices.create', $this->service->lookups());
    }

    public function store(FingerprintDeviceRequest $request): RedirectResponse
    {
        $device = $this->service->create($request->validated());

        // Push and bridge devices authenticate with a per-device secret;
        // generate it now and show it exactly once.
        if ($device->connection_mode->requiresPushSecret()) {
            $plain = $this->pushTokens->regenerate($device);

            return redirect()->route('fingerprint.devices.show', $device)
                ->with('success', __('تم إنشاء جهاز البصمة بنجاح.'))
                ->with('push_token', $plain);
        }

        return redirect()->route('fingerprint.devices.show', $device)->with('success', __('تم إنشاء جهاز البصمة بنجاح.'));
    }

    public function show(FingerprintDevice $fingerprintDevice): View
    {
        return view('fingerprint.devices.show', [
            'device' => $fingerprintDevice->load(['syncLogs.initiator']),
            'logs' => $fingerprintDevice->syncLogs()->with('initiator')->latest('started_at')->paginate(10),
        ]);
    }

    public function edit(FingerprintDevice $fingerprintDevice): View
    {
        return view('fingerprint.devices.edit', [
            'device' => $fingerprintDevice,
            ...$this->service->lookups(),
        ]);
    }

    public function update(FingerprintDeviceRequest $request, FingerprintDevice $fingerprintDevice): RedirectResponse
    {
        $this->service->update($fingerprintDevice, $request->validated());

        // A device switched to a push mode without a secret gets one now.
        if ($fingerprintDevice->connection_mode->requiresPushSecret() && ! $fingerprintDevice->hasPushSecret()) {
            $plain = $this->pushTokens->regenerate($fingerprintDevice);

            return redirect()->route('fingerprint.devices.show', $fingerprintDevice)
                ->with('success', __('تم تحديث جهاز البصمة بنجاح.'))
                ->with('push_token', $plain);
        }

        return redirect()->route('fingerprint.devices.show', $fingerprintDevice)->with('success', __('تم تحديث جهاز البصمة بنجاح.'));
    }

    public function regeneratePushToken(FingerprintDevice $fingerprintDevice): RedirectResponse
    {
        if (! $fingerprintDevice->connection_mode->requiresPushSecret()) {
            return back()->with('error', __('هذا الجهاز يعمل بالسحب من الخادم ولا يستخدم رمز ربط.'));
        }

        $plain = $this->pushTokens->regenerate($fingerprintDevice);

        return back()
            ->with('success', __('تم إنشاء رمز ربط جديد. الرموز السابقة توقفت عن العمل.'))
            ->with('push_token', $plain);
    }

    public function destroy(FingerprintDevice $fingerprintDevice): RedirectResponse
    {
        $this->service->delete($fingerprintDevice);

        return redirect()->route('fingerprint.devices.index')->with('success', __('تم حذف جهاز البصمة بنجاح.'));
    }

    public function testConnection(FingerprintDevice $fingerprintDevice): RedirectResponse
    {
        if (! $fingerprintDevice->usesServerPull()) {
            return back()->with('error', __('اختبار الاتصال متاح فقط للأجهزة التي يسحب منها الخادم مباشرة.'));
        }

        $log = $this->connectionService->testConnection($fingerprintDevice, auth()->id());

        return back()->with($log->status->value === 'success' ? 'success' : 'error', $log->status->value === 'success'
            ? 'تم الاتصال بالجهاز بنجاح.'
            : 'فشل الاتصال بالجهاز: '.$log->error_message);
    }

    public function retrieveInfo(FingerprintDevice $fingerprintDevice): RedirectResponse
    {
        if (! $fingerprintDevice->usesServerPull()) {
            return back()->with('error', __('قراءة معلومات الجهاز متاحة فقط للأجهزة التي يسحب منها الخادم مباشرة.'));
        }

        $log = $this->connectionService->retrieveDeviceInfo($fingerprintDevice, auth()->id());

        return back()->with($log->status->value === 'success' ? 'success' : 'error', $log->status->value === 'success'
            ? 'تم اختبار الاتصال وقراءة بيانات البنية الأساسية.'
            : 'تعذر قراءة معلومات الجهاز: '.$log->error_message);
    }

    public function sync(FingerprintDevice $fingerprintDevice): RedirectResponse
    {
        if (! $fingerprintDevice->usesServerPull()) {
            return back()->with('error', __('المزامنة اليدوية من الخادم متاحة فقط لأجهزة السحب المباشر. أجهزة الإرسال ترسل بصماتها بنفسها.'));
        }

        $log = $this->syncService->syncDevice($fingerprintDevice, auth()->id(), [
            'device_id' => $fingerprintDevice->id,
            'force' => true,
        ]);

        // The sync runs synchronously inside this request, so report its real outcome.
        if ($log->status !== FingerprintSyncStatus::Success) {
            return back()->with('error', __('فشلت المزامنة:').$log->error_message);
        }

        $unresolved = (int) ($log->metadata['unresolved_count'] ?? 0);

        return back()->with('success', "اكتملت المزامنة: {$log->imported_count} بصمة جديدة، {$log->duplicate_count} مكررة، {$unresolved} غير مطابقة.");
    }

    public function syncAll(): RedirectResponse
    {
        $summary = $this->syncService->syncDueDevices(auth()->id(), ['force' => true]);

        return back()->with('success', "اكتملت مزامنة الأجهزة المستحقة: {$summary['success']} ناجحة و{$summary['failed']} فاشلة.");
    }
}
