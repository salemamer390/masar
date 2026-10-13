<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('employees', 'insurance_number')) {
            return;
        }

        DB::table('insurance_records')
            ->whereNotNull('insurance_number')
            ->where('insurance_number', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($records): void {
                foreach ($records as $record) {
                    DB::table('employees')
                        ->where('id', $record->employee_id)
                        ->whereNull('insurance_number')
                        ->update(['insurance_number' => $record->insurance_number]);
                }
            });
    }

    public function down(): void
    {
        // Existing insurance numbers must be retained when rolling back.
    }
};
