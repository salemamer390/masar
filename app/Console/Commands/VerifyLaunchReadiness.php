<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * Post-deploy launch gate: reports whether the environment and application
 * state match the launch requirements (production env, debug off, HTTPS,
 * secure sessions, owner allowlist, utf8mb4 database, no pending migrations,
 * no employee files left on the public disk, private photo route registered).
 * Read-only: it changes nothing. Run after deployment and after
 * masar:migrate-private-storage; every FAIL line names its remedy.
 */
class VerifyLaunchReadiness extends Command
{
    protected $signature = 'masar:verify-launch-readiness';

    protected $description = 'فحص جاهزية الإطلاق والتخزين الخاص بدون تغيير أي شيء';

    public function handle(): int
    {
        $failures = 0;

        $check = function (bool $ok, string $pass, string $fail, bool $warningOnly = false) use (&$failures): void {
            if ($ok) {
                $this->info("OK: {$pass}");
            } elseif ($warningOnly) {
                $this->warn("تحقق يدويًا: {$fail}");
            } else {
                $this->error("فشل: {$fail}");
                $failures++;
            }
        };

        $check(config('app.env') === 'production', 'APP_ENV=production', 'APP_ENV ليس production (الحالي: '.config('app.env').')');
        $check(config('app.debug') === false, 'APP_DEBUG=false', 'APP_DEBUG مفعّل؛ عطّله في الإنتاج');
        $check(str_starts_with((string) config('app.url'), 'https://'), 'APP_URL يبدأ بـ https', 'APP_URL لا يبدأ بـ https: '.config('app.url'));
        $check((bool) config('session.secure'), 'SESSION_SECURE_COOKIE=true', 'SESSION_SECURE_COOKIE غير مفعّل');
        $check(trim((string) env('OWNER_EMAILS')) !== '', 'OWNER_EMAILS مضبوط', 'OWNER_EMAILS فارغ؛ حساب المالك المنفصل لن يُعرف');

        try {
            $collation = (string) (DB::selectOne('SELECT @@collation_database AS c')->c ?? '');
            $check(str_starts_with($collation, 'utf8mb4'), "ترميز قاعدة البيانات {$collation}", "ترميز قاعدة البيانات {$collation}؛ النصوص العربية تحتاج utf8mb4");
        } catch (\Throwable $e) {
            $check(false, '', 'تعذر الاتصال بقاعدة البيانات: '.$e->getMessage());
        }

        try {
            $ran = DB::table('migrations')->pluck('migration');
            $files = collect(File::files(database_path('migrations')))->map(fn ($f) => $f->getFilenameWithoutExtension());
            $pending = $files->diff($ran)->values();
            $check($pending->isEmpty(), 'لا migrations معلّقة', 'migrations معلّقة ('.$pending->count().'): '.$pending->take(5)->implode('، ').' — شغّل php artisan migrate العادي');
        } catch (\Throwable $e) {
            $check(false, '', 'تعذر فحص الـ migrations: '.$e->getMessage());
        }

        try {
            $legacy = 0;
            foreach (Document::withoutGlobalScopes()->whereNotNull('file_path')->pluck('file_path') as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    $legacy++;
                }
            }
            foreach (Employee::withoutGlobalScopes()->whereNotNull('profile_photo_path')->pluck('profile_photo_path') as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    $legacy++;
                }
            }
            $check($legacy === 0, 'لا مستندات أو صور موظفين متبقية على القرص العام', "{$legacy} ملف ما زال على القرص العام؛ شغّل masar:migrate-private-storage ثم احظر المسارات القديمة من السيرفر");
        } catch (\Throwable $e) {
            $check(false, '', 'تعذر فحص الملفات العامة: '.$e->getMessage());
        }

        $check(Route::has('employees.photo.show'), 'مسار عرض الصور الخاص مسجّل', 'مسار employees.photo.show غير مسجّل؛ ادمج تحديثات التخزين الخاص (PR #35) أولاً');
        $check(is_link(public_path('storage')) || is_dir(public_path('storage')), 'رابط public/storage موجود', 'public/storage غير موجود؛ شغّل php artisan storage:link إن كانت ملفات عامة أخرى تستخدمه', true);

        if ($failures > 0) {
            $this->error("{$failures} فحص فشل. عالج البنود أعلاه قبل اعتبار النسخة جاهزة.");

            return self::FAILURE;
        }

        $this->info('كل فحوص الجاهزية ناجحة.');

        return self::SUCCESS;
    }
}
