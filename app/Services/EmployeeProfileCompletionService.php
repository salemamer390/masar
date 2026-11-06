<?php

namespace App\Services;

use App\Models\DocumentType;
use App\Models\Employee;
use Illuminate\Support\Collection;

class EmployeeProfileCompletionService
{
    /** @return Collection<int, DocumentType> */
    public function requiredDocumentTypes(): Collection
    {
        return DocumentType::query()->where('is_required', true)->where('is_active', true)->orderBy('name')->get();
    }

    /** @return Collection<int, DocumentType> */
    public function applicableRequiredDocumentTypes(Employee $employee, ?Collection $requiredDocumentTypes = null): Collection
    {
        $requiredDocumentTypes ??= $this->requiredDocumentTypes();

        return $requiredDocumentTypes->reject(function (DocumentType $type) use ($employee): bool {
            $isMaleOnlyDocument = str_contains($type->name, 'الخدمة العسكرية');
            $gender = mb_strtolower(trim((string) $employee->gender));
            $isFemale = in_array($gender, ['female', 'أنثى', 'انثى'], true);

            return $isMaleOnlyDocument && $isFemale;
        })->values();
    }

    /**
     * A profile is complete only when required HR data and required documents
     * are present. Saving remains allowed; only the completion label is gated.
     *
     * @return array{complete: bool, missing_fields: array<int, string>, missing_documents: array<int, string>, required_documents: int}
     */
    public function evaluate(Employee $employee, ?Collection $requiredDocumentTypes = null): array
    {
        $profile = $employee->profileDetail;
        $requiredDocumentTypes = $this->applicableRequiredDocumentTypes($employee, $requiredDocumentTypes);
        $values = [
            'الرقم القومي' => $employee->national_id,
            'رقم الهاتف' => $employee->phone,
            'بريد العمل' => $employee->email,
            'العنوان' => $employee->address,
            'القسم' => $employee->department_id,
            'الوظيفة' => $employee->job_id,
            'بداية العقد' => $employee->contract_start_date,
            'نهاية العقد' => $employee->contract_end_date,
            'الراتب الإجمالي' => (float) $employee->gross_salary > 0 ? $employee->gross_salary : null,
            'الجنسية' => $profile?->nationality,
            'البريد الشخصي' => $profile?->personal_email,
            'رصيد الإجازة السنوي' => $profile?->annual_leave_days,
        ];
        $missingFields = collect($values)->filter(fn ($value) => blank($value) && $value !== 0 && $value !== '0')->keys()->values()->all();

        $validDocumentTypeIds = $employee->documents
            ->filter(fn ($document) => $document->file_path && ($document->expiry_date === null || $document->expiry_date->gte(today())))
            ->pluck('document_type_id')->filter()->unique();
        $missingDocuments = $requiredDocumentTypes->whereNotIn('id', $validDocumentTypeIds)->pluck('name')->values()->all();

        return [
            'complete' => $missingFields === [] && $missingDocuments === [],
            'missing_fields' => $missingFields,
            'missing_documents' => $missingDocuments,
            'required_documents' => $requiredDocumentTypes->count(),
        ];
    }
}
