<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/** Separate, removable Legal-sized S6 termination-notice template. */
class EditableS6Controller extends Controller
{
    public function show(Employee $employee)
    {
        $employee->load(['insuranceRecord', 'profileDetail']);
        $organization = DB::table('insurance_organization_settings')->first();
        $record = $employee->insuranceRecord;
        $endDate = $record?->insurance_end_date ?? $employee->termination_date;

        return view('forms.s6-editable', ['d' => [
            'office' => $record?->insurance_office ?: ($organization?->office_name ?? ''),
            'applicant_name' => $organization?->applicant_name ?? '',
            'applicant_role' => $organization?->applicant_title ?? '',
            'applicant_ins_no' => $this->digits($organization?->applicant_insurance_number),
            'applicant_nid' => $this->digits($organization?->applicant_national_id),
            'applicant_phone' => $this->digits($organization?->phone),
            'ins_no' => $this->digits($record?->insurance_number),
            'nid' => $this->digits($employee->national_id),
            'name' => $employee->full_name ?? '',
            'est_name' => $organization?->organization_name ?? '',
            'est_no' => $this->digits($organization?->establishment_number),
            'end_day' => $this->arabicDigits($endDate?->format('d') ?? ''),
            'end_month' => $this->arabicDigits($endDate?->format('m') ?? ''),
            'end_year' => $this->arabicDigits($endDate?->format('Y') ?? ''),
            'end_reason' => '',
            'issue_date' => $this->arabicDigits(now()->format('d / m / Y')),
        ]]);
    }

    private function digits($value): string
    {
        $latin = preg_replace('/\D+/', '', strtr((string) $value, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']));
        return $this->arabicDigits($latin);
    }

    private function arabicDigits(string $value): string
    {
        return strtr($value, ['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);
    }
}
