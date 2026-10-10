<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/** Separate, removable Legal-sized S1 template imported from the editable package. */
class EditableS1Controller extends Controller
{
    /** Print-ready batch of S1 forms for every employee in the system. */
    public function printAll()
    {
        $employees = Employee::query()
            ->with(['job', 'department', 'profileDetail', 'insuranceRecord', 'currentSalary'])
            ->orderBy('full_name')
            ->get();

        $forms = $employees->map(function (Employee $employee): array {
            return $this->show($employee)->getData()['d'];
        });

        return view('forms.s1-bulk', compact('forms'));
    }

    public function show(Employee $employee)
    {
        $employee->load(['job', 'department', 'profileDetail', 'insuranceRecord', 'currentSalary']);
        $organization = DB::table('insurance_organization_settings')->first();
        $record = $employee->insuranceRecord;
        $salary = $employee->currentSalary;
        $category = (string) ($record?->insurance_category ?? 'العاملون لدى الغير');
        $category = str_contains($category, 'مخابز') ? 3 : (str_contains($category, 'أصحاب') ? 2 : 1);
        $start = $record?->insurance_start_date;
        $profile = $employee->profileDetail;
        $data = [
            'office' => $record?->insurance_office ?: ($organization?->office_name ?? ''),
            'applicant_name' => $organization?->applicant_name ?? '',
            'applicant_role' => $organization?->applicant_title ?? '',
            'applicant_ins_no' => $this->digits($organization?->applicant_insurance_number),
            'applicant_nid' => $this->digits($organization?->applicant_national_id),
            'applicant_phone' => $this->digits($organization?->phone),
            'category' => $category,
            'ins_no' => $this->digits($record?->insurance_number),
            'nid' => $this->digits($employee->national_id),
            'name' => $employee->full_name ?? '',
            'nationality' => $profile?->nationality ?? 'مصري',
            'qualification' => $employee->qualification ?? '',
            'job' => $employee->job?->title ?? '',
            'start_day' => $this->arabicDigits($start?->format('d') ?? ''), 'start_month' => $this->arabicDigits($start?->format('m') ?? ''), 'start_year' => $this->arabicDigits($start?->format('Y') ?? ''),
            'sector' => '', 'sub_code' => '', 'period_type' => '',
            'wage' => $this->money($salary?->applied_insurance_wage ?? $record?->insured_salary),
            'total_wage' => $this->money($employee->gross_salary),
            'dis_day' => '', 'dis_month' => '', 'dis_year' => '', 'dis_percent' => '', 'medical' => 1,
            'est_type' => 1, 'est_name' => $organization?->organization_name ?? '', 'est_no' => $this->digits($organization?->establishment_number), 'est_address' => $organization?->address ?? '',
            'addr_building' => '', 'addr_street' => $employee->address ?? '', 'addr_village' => '', 'addr_district' => '', 'addr_governorate' => '',
            'phone' => $this->digits($employee->phone), 'issue_date' => $this->arabicDigits(now()->format('d / m / Y')),
        ];
        return view('forms.s1-editable', ['d' => $data]);
    }

    private function digits($value): string
    {
        $latin = preg_replace('/\D+/', '', strtr((string) $value, ['٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']));
        return $this->arabicDigits($latin);
    }

    private function money($value): string
    {
        return $value === null ? '' : $this->arabicDigits((string) (int) round((float) $value));
    }

    private function arabicDigits(string $value): string
    {
        return strtr($value, ['0'=>'٠','1'=>'١','2'=>'٢','3'=>'٣','4'=>'٤','5'=>'٥','6'=>'٦','7'=>'٧','8'=>'٨','9'=>'٩']);
    }
}
