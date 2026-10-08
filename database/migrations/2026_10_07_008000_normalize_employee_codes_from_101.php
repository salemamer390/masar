<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Give existing employee records a stable, human-facing sequence. */
    public function up(): void
    {
        $employees = DB::table('employees')->orderBy('id')->pluck('id');

        DB::transaction(function () use ($employees): void {
            foreach ($employees as $id) {
                DB::table('employees')->where('id', $id)->update(['employee_code' => '__TMP_EMP_'.$id]);
            }

            foreach ($employees->values() as $offset => $id) {
                DB::table('employees')->where('id', $id)->update(['employee_code' => (string) (101 + $offset)]);
            }
        });
    }

    public function down(): void
    {
        // Employee codes are business identifiers. Never invent replacement codes on rollback.
    }
};
