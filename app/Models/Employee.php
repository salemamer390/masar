<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;
use App\Models\Concerns\BelongsToCompany;

class Employee extends Model
{
    // Company isolation is enforced by the shared trait: the global scope
    // restricts every read to the actor's company (a company-less platform
    // account keeps platform-wide visibility), and the saving guard blocks
    // writes whose company_id does not match the acting company account.
    use BelongsToCompany;
    protected $fillable = [
        'employee_code',
        'fingerprint_id',
        'first_name',
        'middle_name',
        'last_name',
        'full_name',
        'national_id',
        'insurance_number',
        'phone',
        'email',
        'profile_photo_path',
        'gross_salary',
        'gender',
        'marital_status',
        'qualification',
        'birth_date',
        'expected_delivery_date',
        'pregnancy_reduction_start',
        'address',
        'department_id',
        'job_id',
        'branch_id',
        'employment_type_id',
        'status_id',
        'pre_leave_status_id',
        'hire_date',
        'contract_start_date',
        'contract_end_date',
        'contract_duration',
        'termination_date',
        'return_date',
        'termination_type',
        'shift_start',
        'shift_end',
        'manual_annual_balance',
        'leave_entitlement_category',
        'manager_employee_id',
        'company_id',
        'notes',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'expected_delivery_date' => 'date',
        'pregnancy_reduction_start' => 'date',
        'hire_date' => 'date',
        'contract_start_date' => 'date',
        'contract_end_date' => 'date',
        'termination_date' => 'date',
        'return_date' => 'date',
        'manual_annual_balance' => 'decimal:2',
        'gross_salary' => 'decimal:2',
    ];

    /**
     * contract_start_date is the business date; hire_date is retained only as
     * a synchronized compatibility column for older clients and exports.
     * Query-builder writes must set both (the migration checks legacy rows).
     */
    protected static function booted(): void
    {
        static::saving(function (Employee $employee): void {
            $startChanged = $employee->isDirty('contract_start_date');
            $legacyChanged = $employee->isDirty('hire_date');
            if (!$startChanged && !$legacyChanged) return;

            $start = $employee->contract_start_date?->format('Y-m-d');
            $legacy = $employee->hire_date?->format('Y-m-d');
            if ($startChanged && $legacyChanged) {
                \App\Support\EmploymentStartDate::resolve($start, $legacy);
            }
            $date = $startChanged ? $start : $legacy;
            $date ??= $start ?? $legacy;
            $employee->contract_start_date = $date;
            $employee->hire_date = $date;
        });
    }

    public function fill(array $attributes)
    {
        if (array_key_exists('contract_start_date', $attributes) || array_key_exists('hire_date', $attributes)) {
            $date = \App\Support\EmploymentStartDate::resolve($attributes['contract_start_date'] ?? null, $attributes['hire_date'] ?? null);
            $attributes['contract_start_date'] = $date;
            $attributes['hire_date'] = $date;
        }

        return parent::fill($attributes);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function company(): BelongsTo { return $this->belongsTo(Company::class); }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(EmployeeStatus::class, 'status_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'manager_employee_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->orderBy('id', 'asc');
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmployeeEmergencyContact::class)->orderByDesc('is_primary');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(EmployeeBankAccount::class)->orderByDesc('is_primary')->orderByDesc('effective_from')->latest('id');
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(Leave::class)->orderBy('start_date', 'desc');
    }

    public function hrRequests(): HasMany
    {
        return $this->hasMany(HrRequest::class)->orderByDesc('id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class)->orderBy('attendance_date', 'desc');
    }

    public function salaries(): HasMany
    {
        return $this->hasMany(Salary::class)->orderBy('effective_from', 'desc');
    }

    public function currentSalary(): HasOne
    {
        return $this->hasOne(Salary::class)->latestOfMany('effective_from');
    }

    public function payrollRecords(): HasMany
    {
        return $this->hasMany(PayrollRecord::class)->orderBy('payroll_month', 'desc');
    }

    public function insuranceRecord(): HasOne
    {
        return $this->hasOne(InsuranceRecord::class)->latestOfMany();
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(Penalty::class)->orderBy('penalty_date', 'desc');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(MovementRecord::class)->orderBy('movement_date', 'desc');
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function profileDetail(): HasOne
    {
        return $this->hasOne(EmployeeProfileDetail::class);
    }

    public function getInitialsAttribute(): string
    {
        $parts = preg_split('/\s+/', trim($this->full_name));
        if (count($parts) >= 2) {
            return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }
        return mb_strtoupper(mb_substr($this->full_name, 0, 2));
    }

    public function getProfilePhotoUrlAttribute(): ?string
    {
        return $this->profile_photo_path ? route('employees.photo.show', $this) : null;
    }

    /** Legacy public photos remain readable through the guarded route until migrated. */
    public function profilePhotoDisk(): string
    {
        return $this->profile_photo_path && Storage::disk('local')->exists($this->profile_photo_path) ? 'local' : 'public';
    }
}
