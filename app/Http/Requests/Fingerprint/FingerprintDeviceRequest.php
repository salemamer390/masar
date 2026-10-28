<?php

namespace App\Http\Requests\Fingerprint;

use App\Enums\FingerprintDeviceDriver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FingerprintDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission($this->route('fingerprintDevice') ? 'fingerprint-devices.update' : 'fingerprint-devices.create') ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'device_type' => ['required', 'string', 'max:60'],
            'driver' => ['required', Rule::in(collect(FingerprintDeviceDriver::cases())->pluck('value')->all())],
            'ip_address' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'communication_key' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'firmware_version' => ['nullable', 'string', 'max:255'],
            'timezone' => ['required', 'timezone'],
            'physical_location' => ['nullable', 'string', 'max:255'],
            'sync_enabled' => ['required', 'boolean'],
            'sync_interval_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'is_active' => ['required', 'boolean'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ];
    }
}
