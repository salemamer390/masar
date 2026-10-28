<?php

namespace App\Http\Requests\Fingerprint;

use App\Enums\FingerprintSyncStatus;
use App\Enums\FingerprintSyncType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FingerprintSyncLogFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('fingerprint-sync-logs.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'device_id' => ['nullable', 'integer', Rule::exists('fingerprint_devices', 'id')],
            'status' => ['nullable', Rule::in(collect(FingerprintSyncStatus::cases())->pluck('value')->all())],
            'sync_type' => ['nullable', Rule::in(collect(FingerprintSyncType::cases())->pluck('value')->all())],
        ];
    }
}
