<?php

namespace Tests\Feature;

use App\Models\{Company, Employee, EmployeeStatus};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemoveGlobalPenaltyTypesMigrationTest extends TestCase
{
    use DatabaseTransactions;

    private function employee(Company $company, string $code): Employee
    {
        $status = EmployeeStatus::firstOrCreate(['name' => 'Active']);
        return Employee::withoutGlobalScopes()->create(['employee_code' => $code, 'first_name' => $code, 'full_name' => $code, 'company_id' => $company->id, 'status_id' => $status->id, 'hire_date' => '2025-01-01', 'shift_start' => '09:00', 'shift_end' => '17:00']);
    }

    private function penalty(Company $company, Employee $e, int $typeId): int
    {
        return DB::table('penalties')->insertGetId(['company_id' => $company->id, 'employee_id' => $e->id, 'penalty_type_id' => $typeId, 'penalty_date' => '2026-11-03', 'title' => 'x', 'reason' => 'x', 'action' => 'x', 'amount' => 0, 'deduction_days' => 0, 'status' => 'Open', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_used_global_types_become_company_types_and_globals_are_removed(): void
    {
        $a = Company::create(['name' => 'A', 'slug' => 'rgpt-a', 'status' => 'active']);
        $b = Company::create(['name' => 'B', 'slug' => 'rgpt-b', 'status' => 'active']);
        $ea = $this->employee($a, 'RGA'); $eb = $this->employee($b, 'RGB');
        $used = DB::table('penalty_types')->insertGetId(['name' => 'نوع عام مستخدم', 'is_active' => 1, 'default_deduction_days' => 1, 'company_id' => null]);
        $unused = DB::table('penalty_types')->insertGetId(['name' => 'نوع عام غير مستخدم', 'is_active' => 1, 'default_deduction_days' => 0, 'company_id' => null]);
        $ownA = DB::table('penalty_types')->insertGetId(['name' => 'نوع عام مستخدم', 'is_active' => 1, 'default_deduction_days' => 0, 'company_id' => $a->id]);
        $pa = $this->penalty($a, $ea, $used); $pb = $this->penalty($b, $eb, $used);

        $migration = require database_path('migrations/2026_11_23_000001_remove_global_penalty_types.php');
        $migration->up();

        $this->assertSame(0, DB::table('penalty_types')->whereNull('company_id')->count());
        $this->assertSame($ownA, (int) DB::table('penalties')->where('id', $pa)->value('penalty_type_id'));
        $newB = DB::table('penalties')->where('id', $pb)->first();
        $type = DB::table('penalty_types')->where('id', $newB->penalty_type_id)->first();
        $this->assertSame($b->id, (int) $type->company_id);
        $this->assertSame('نوع عام مستخدم', $type->name);
        $this->assertSame(2, DB::table('penalties')->whereIn('id', [$pa, $pb])->count());
        $this->assertFalse(DB::table('penalty_types')->where('id', $unused)->exists());
    }
}
