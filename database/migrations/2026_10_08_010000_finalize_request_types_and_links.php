<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('request_types')) {
            $types = [
                ['code' => 'leave', 'display_name' => 'إجازة', 'source_key' => 'leave', 'icon' => 'bi-calendar-check', 'icon_color' => '#EF4444', 'icon_background' => '#FEF2F2', 'metadata' => json_encode(['module' => 'leaves'], JSON_UNESCAPED_UNICODE), 'is_active' => true, 'sort_order' => 10],
                ['code' => 'advance', 'display_name' => 'سلفة', 'source_key' => 'advance', 'icon' => 'bi-cash-stack', 'icon_color' => '#10B981', 'icon_background' => '#ECFDF5', 'metadata' => json_encode(['module' => 'advances'], JSON_UNESCAPED_UNICODE), 'is_active' => true, 'sort_order' => 20],
                ['code' => 'permission', 'display_name' => 'إذن', 'source_key' => 'permission', 'icon' => 'bi-file-signature', 'icon_color' => '#F59E0B', 'icon_background' => '#FFFBEB', 'metadata' => json_encode(['module' => 'permissions'], JSON_UNESCAPED_UNICODE), 'is_active' => true, 'sort_order' => 30],
                ['code' => 'mission', 'display_name' => 'مأمورية', 'source_key' => 'mission', 'icon' => 'bi-briefcase', 'icon_color' => '#2563EB', 'icon_background' => '#EFF6FF', 'metadata' => json_encode(['module' => 'missions'], JSON_UNESCAPED_UNICODE), 'is_active' => true, 'sort_order' => 40],
                ['code' => 'document_request', 'display_name' => 'طلب مستند', 'source_key' => 'document_request', 'icon' => 'bi-file-earmark-text', 'icon_color' => '#7C3AED', 'icon_background' => '#F5F3FF', 'metadata' => json_encode(['module' => 'documents'], JSON_UNESCAPED_UNICODE), 'is_active' => true, 'sort_order' => 50],
                ['code' => 'other', 'display_name' => 'طلب آخر', 'source_key' => 'other', 'icon' => 'bi-clipboard-check', 'icon_color' => '#64748B', 'icon_background' => '#F1F5F9', 'metadata' => json_encode(['module' => 'requests'], JSON_UNESCAPED_UNICODE), 'is_active' => true, 'sort_order' => 60],
            ];

            foreach ($types as $type) {
                DB::table('request_types')->updateOrInsert(
                    ['code' => $type['code']],
                    $type + ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }

        if (Schema::hasTable('hr_requests') && Schema::hasTable('request_types') && !Schema::hasColumn('hr_requests', 'request_type_id')) {
            Schema::table('hr_requests', function (Blueprint $table) {
                $table->foreignId('request_type_id')->nullable()->after('request_type');
            });
        }

        if (Schema::hasTable('hr_requests') && Schema::hasTable('request_types') && Schema::hasColumn('hr_requests', 'request_type_id')) {
            DB::statement(<<<'SQL'
                UPDATE hr_requests r
                INNER JOIN request_types rt ON rt.code = r.request_type
                SET r.request_type_id = rt.id
                WHERE r.request_type_id IS NULL
            SQL);

            // Match the constraint used by the existing SQL finalization script (`fk_hr_requests_request_type`)
            // and detect the relationship by column/reference so we never create a duplicate FK on an audited DB.
            $foreignKeyExists = DB::table('information_schema.KEY_COLUMN_USAGE')
                ->whereRaw('CONSTRAINT_SCHEMA = DATABASE()')
                ->where('TABLE_NAME', 'hr_requests')
                ->where('COLUMN_NAME', 'request_type_id')
                ->where('REFERENCED_TABLE_NAME', 'request_types')
                ->where('REFERENCED_COLUMN_NAME', 'id')
                ->exists();

            if (!$foreignKeyExists) {
                Schema::table('hr_requests', function (Blueprint $table) {
                    $table->foreign('request_type_id', 'fk_hr_requests_request_type')
                        ->references('id')->on('request_types')->restrictOnDelete()->cascadeOnUpdate();
                });
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('hr_requests') || !Schema::hasColumn('hr_requests', 'request_type_id')) {
            return;
        }

        if (Schema::hasColumn('hr_requests', 'request_type_id')) {
            Schema::table('hr_requests', function (Blueprint $table) {
                try {
                    $table->dropForeign('fk_hr_requests_request_type');
                } catch (\Throwable) {
                    // The audited/manual SQL finalization may own the FK; leave it intact on rollback.
                }
                $table->dropColumn('request_type_id');
            });
        }
    }
};
