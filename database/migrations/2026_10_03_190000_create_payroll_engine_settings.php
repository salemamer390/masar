<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tax_settings')) {
            Schema::create('tax_settings', function (Blueprint $table) {
                $table->id();
                $table->string('setting_key')->unique();
                $table->decimal('setting_value', 14, 4)->nullable();
                $table->string('label')->nullable();
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('tax_categories')) {
            Schema::create('tax_categories', function (Blueprint $table) {
                $table->id();
                $table->string('category_key')->unique();
                $table->string('label');
                $table->decimal('min_income', 14, 2)->default(0);
                $table->decimal('max_income', 14, 2)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('tax_brackets')) {
            Schema::create('tax_brackets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tax_category_id')->constrained('tax_categories')->cascadeOnDelete();
                $table->decimal('from_amount', 14, 2)->default(0);
                $table->decimal('to_amount', 14, 2)->nullable();
                $table->decimal('rate', 8, 6)->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }
        if (!Schema::hasTable('insurance_settings')) {
            Schema::create('insurance_settings', function (Blueprint $table) {
                $table->id();
                $table->string('setting_key')->unique();
                $table->decimal('setting_value', 14, 6)->nullable();
                $table->string('label')->nullable();
                $table->timestamps();
            });
        }

        $settings = [
            ['personal_exemption', 20000, 'الإعفاء الشخصي السنوي'],
        ];
        foreach ($settings as [$key,$value,$label]) {
            DB::table('tax_settings')->updateOrInsert(['setting_key'=>$key], ['setting_value'=>$value,'label'=>$label,'updated_at'=>now(),'created_at'=>now()]);
        }

        $categories = [
            ['category_1','الفئة الأولى — حتى 600,000',0,600000,1],
            ['category_2','الفئة الثانية — أكثر من 600,000 حتى 700,000',600000.01,700000,2],
            ['category_3','الفئة الثالثة — أكثر من 700,000 حتى 800,000',700000.01,800000,3],
            ['category_4','الفئة الرابعة — أكثر من 800,000 حتى 900,000',800000.01,900000,4],
            ['category_5','الفئة الخامسة — أكثر من 900,000 حتى 1,200,000',900000.01,1200000,5],
            ['category_6','الفئة السادسة — أكثر من 1,200,000',1200000.01,null,6],
        ];
        foreach ($categories as [$key,$label,$min,$max,$sort]) {
            DB::table('tax_categories')->updateOrInsert(['category_key'=>$key], ['label'=>$label,'min_income'=>$min,'max_income'=>$max,'sort_order'=>$sort,'updated_at'=>now(),'created_at'=>now()]);
        }

        $brackets = [
            'category_1'=>[[0,40000,0],[40000,55000,.10],[55000,70000,.15],[70000,200000,.20],[200000,400000,.225],[400000,null,.25]],
            'category_2'=>[[0,55000,.10],[55000,70000,.15],[70000,200000,.20],[200000,400000,.225],[400000,null,.25]],
            'category_3'=>[[0,70000,.15],[70000,200000,.20],[200000,400000,.225],[400000,null,.25]],
            'category_4'=>[[0,200000,.20],[200000,400000,.225],[400000,null,.25]],
            'category_5'=>[[0,400000,.225],[400000,null,.25]],
            'category_6'=>[[0,1200000,.25],[1200000,null,.275]],
        ];
        foreach ($brackets as $key=>$rows) {
            $categoryId = DB::table('tax_categories')->where('category_key',$key)->value('id');
            DB::table('tax_brackets')->where('tax_category_id',$categoryId)->delete();
            foreach ($rows as $i=>[$from,$to,$rate]) {
                DB::table('tax_brackets')->insert(['tax_category_id'=>$categoryId,'from_amount'=>$from,'to_amount'=>$to,'rate'=>$rate,'sort_order'=>$i,'created_at'=>now(),'updated_at'=>now()]);
            }
        }

        $insurance = [
            ['minimum_insurance_wage',2700,'الحد الأدنى للأجر التأميني'],
            ['maximum_insurance_wage',16700,'الحد الأقصى للأجر التأميني'],
            ['employee_rate',.11,'نسبة الموظف'],
            ['employer_rate',.1875,'نسبة صاحب العمل'],
            ['martyrs_rate',.0005,'نسبة صندوق الشهداء'],
        ];
        foreach ($insurance as [$key,$value,$label]) {
            DB::table('insurance_settings')->updateOrInsert(['setting_key'=>$key], ['setting_value'=>$value,'label'=>$label,'updated_at'=>now(),'created_at'=>now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_brackets');
        Schema::dropIfExists('tax_categories');
        Schema::dropIfExists('tax_settings');
        Schema::dropIfExists('insurance_settings');
    }
};
