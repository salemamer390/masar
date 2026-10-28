<?php

namespace App\Http\Requests\Fingerprint;

use Illuminate\Foundation\Http\FormRequest;

class FingerprintDeviceFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('fingerprint-devices.view') ?? false;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:0,1'],
        ];
    }
}
