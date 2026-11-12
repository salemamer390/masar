<?php

use App\Support\GuidedTour;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Editable guided-tour steps for the owner panel. Seeded from the
     * built-in tour definition so the published tour is byte-identical to
     * the hardcoded one on day one; GuidedTour::stepsFor() falls back to
     * the built-in definition whenever the table is missing or empty.
     */
    public function up(): void
    {
        Schema::create('tour_steps', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);
            $table->string('route_name', 120)->nullable();
            $table->json('target')->nullable();
            $table->string('icon', 60)->nullable();
            $table->string('permission', 120)->nullable();
            $table->text('title_ar');
            $table->text('body_ar');
            $table->text('title_en')->nullable();
            $table->text('body_en')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_draft')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['key', 'is_draft']);
        });

        $now = now();
        foreach (GuidedTour::defaults() as $index => $step) {
            DB::table('tour_steps')->insert([
                'key' => $step['key'],
                'route_name' => $step['route'],
                'target' => $step['target'] ? json_encode(array_values($step['target']), JSON_UNESCAPED_UNICODE) : null,
                'icon' => $step['icon'],
                'permission' => $step['permission'],
                'title_ar' => $step['title'],
                'body_ar' => $step['body'],
                'title_en' => self::english($step['title']),
                'body_en' => self::english($step['body']),
                'sort_order' => ($index + 1) * 10,
                'is_active' => true,
                'is_system' => $step['system'],
                'is_draft' => false,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * English text from the translation files; null when the string has no
     * translation yet so the Arabic source is used at runtime.
     */
    private static function english(string $arabic): ?string
    {
        $translated = __($arabic, [], 'en');

        return $translated === $arabic ? null : $translated;
    }

    public function down(): void
    {
        Schema::dropIfExists('tour_steps');
    }
};
