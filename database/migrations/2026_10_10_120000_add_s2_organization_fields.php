<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('insurance_organization_settings')) {
            return;
        }

        Schema::table('insurance_organization_settings', function (Blueprint $table) {
            foreach ([
                'sector' => ['string', 150],
                'tax_registration_number' => ['string', 100],
                'commercial_registration_number' => ['string', 100],
                'unified_commercial_registration_number' => ['string', 100],
            ] as $column => [$type, $length]) {
                if (!Schema::hasColumn('insurance_organization_settings', $column)) {
                    $table->{$type}($column, $length)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('insurance_organization_settings')) {
            return;
        }

        Schema::table('insurance_organization_settings', function (Blueprint $table) {
            foreach ([
                'sector',
                'tax_registration_number',
                'commercial_registration_number',
                'unified_commercial_registration_number',
            ] as $column) {
                if (Schema::hasColumn('insurance_organization_settings', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
