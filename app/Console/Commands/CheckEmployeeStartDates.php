<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CheckEmployeeStartDates extends Command
{
    protected $signature = 'employees:check-start-dates';
    protected $description = 'Read-only preflight for contract-start/hire-date consolidation. Reports conflicting record IDs only.';

    public function handle(): int
    {
        $rows = DB::table('employees')->whereNotNull('hire_date')->whereNotNull('contract_start_date')
            ->whereColumn('hire_date', '<>', 'contract_start_date')->orderBy('id')->get(['id', 'company_id', 'hire_date', 'contract_start_date']);
        if ($rows->isEmpty()) {
            $this->info('No conflicting start dates. Missing mirror values can be safely backfilled by the migration.');
            return self::SUCCESS;
        }
        $this->table(['Employee ID', 'Company ID', 'Legacy hire date', 'Contract start'], $rows->map(fn ($row) => (array) $row)->all());
        $this->error('Conflicting dates require HR review. No changes were made.');
        return self::FAILURE;
    }
}
