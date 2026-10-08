<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_types')) return;
        if (! Schema::hasColumn('document_types', 'category')) {
            Schema::table('document_types', function (Blueprint $table): void {
                $table->string('category', 30)->default('basic')->index()->after('name');
            });
        }

        $types = [
            'أصل شهادة الميلاد' => ['basic', true],
            'بطاقة الرقم القومي' => ['basic', true],
            'أصل المؤهل الدراسي' => ['basic', true],
            'شهادة الخدمة العسكرية للذكور' => ['basic', true],
            'الفيش الجنائي' => ['basic', true],
            'نموذج 111 (الكشف الطبي المبدئي)' => ['job', false],
            'كارنيه النقابة لأصحاب المهن' => ['job', false],
            'شهادة قياس مهارة للحرفيين' => ['job', false],
            'الشهادة الصحية للعاملين بالأغذية' => ['job', false],
            'كعب العمل' => ['workplace', false],
            'شهادة قيد' => ['workplace', false],
            'برنت تأمينات' => ['workplace', false],
        ];

        foreach ($types as $name => [$category, $required]) {
            DB::table('document_types')->updateOrInsert(
                ['name' => $name],
                ['category' => $category, 'is_required' => $required, 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('document_types')) {
            DB::table('document_types')->whereIn('name', [
                'أصل شهادة الميلاد','بطاقة الرقم القومي','أصل المؤهل الدراسي','شهادة الخدمة العسكرية للذكور',
                'الفيش الجنائي','نموذج 111 (الكشف الطبي المبدئي)','كارنيه النقابة لأصحاب المهن','شهادة قياس مهارة للحرفيين',
                'الشهادة الصحية للعاملين بالأغذية','كعب العمل','شهادة قيد','برنت تأمينات',
            ])->delete();
        }
        if (Schema::hasColumn('document_types', 'category')) Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('category'));
    }
};
