<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $sql = file_get_contents(database_path('schema/tazamun_hr_schema.sql'));
        DB::unprepared($sql);
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'movement_records','penalties','insurance_records','payroll_records','salaries',
            'documents','leaves','attendance','users','employees','role_permissions',
            'permissions','roles','payment_methods','penalty_types','movement_types',
            'leave_types','employment_types','employee_statuses','branches','jobs','departments'
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
};
