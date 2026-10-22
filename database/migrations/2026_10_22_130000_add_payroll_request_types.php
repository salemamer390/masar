<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('request_types')) return;

        foreach ([
            ['code'=>'bonus','display_name'=>'مكافأة','source_key'=>'bonus','icon'=>'bi-gift','icon_color'=>'#16A34A','icon_background'=>'#ECFDF5','sort_order'=>70],
            ['code'=>'incentive','display_name'=>'حافز','source_key'=>'incentive','icon'=>'bi-graph-up-arrow','icon_color'=>'#2563EB','icon_background'=>'#EFF6FF','sort_order'=>80],
            ['code'=>'commission','display_name'=>'عمولة','source_key'=>'commission','icon'=>'bi-percent','icon_color'=>'#7C3AED','icon_background'=>'#F5F3FF','sort_order'=>90],
            ['code'=>'profit_share','display_name'=>'نصيب الأرباح','source_key'=>'profit_share','icon'=>'bi-pie-chart','icon_color'=>'#D97706','icon_background'=>'#FFFBEB','sort_order'=>100],
        ] as $type) {
            DB::table('request_types')->updateOrInsert(
                ['code' => $type['code'], 'company_id' => null],
                array_merge($type, ['metadata'=>json_encode(['module'=>'payroll'], JSON_UNESCAPED_UNICODE), 'is_active'=>true, 'company_id'=>null, 'updated_at'=>now(), 'created_at'=>now()])
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('request_types')) DB::table('request_types')->whereIn('code', ['bonus','incentive','commission','profit_share'])->whereNull('company_id')->delete();
    }
};
