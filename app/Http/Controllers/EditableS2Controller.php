<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;

/** S2 insurance wage-adjustment form, populated from establishment settings. */
class EditableS2Controller extends Controller
{
    public function show()
    {
        $organization = DB::table('insurance_organization_settings')->first();

        $data = [
            'office' => $organization?->office_name ?? '',
            'establishment_number' => $this->digits($organization?->establishment_number),
            'applicant_name' => $organization?->applicant_name ?? '',
            'applicant_title' => $organization?->applicant_title ?? '',
            'applicant_insurance_number' => $this->digits($organization?->applicant_insurance_number),
            'applicant_national_id' => $this->digits($organization?->applicant_national_id),
            'phone' => $this->digits($organization?->phone),
            'organization_name' => $organization?->organization_name ?? '',
            'sector' => $organization?->sector ?? '',
            'tax_registration_number' => $this->digits($organization?->tax_registration_number),
            'commercial_registration_number' => $this->digits($organization?->commercial_registration_number),
            'unified_commercial_registration_number' => $this->digits($organization?->unified_commercial_registration_number),
        ];

        return view('forms.s2-editable', ['d' => $data]);
    }

    private function digits($value): string
    {
        return strtr((string) ($value ?? ''), [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        ]);
    }
}
