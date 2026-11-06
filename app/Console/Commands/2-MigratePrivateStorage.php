<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Launch-rollout helper: moves employee documents and profile photos that
 * still live on the public disk onto the private local disk. Database paths
 * stay unchanged; the models already serve files through the authenticated,
 * company-scoped routes and fall back to the public disk only until a file
 * is migrated. A public copy is deleted only after the private copy is
 * verified byte-identical, so no reference can silently break. Take a
 * verified backup of storage/app before running this without --dry-run,
 * then block the old public paths at the web server.
 */
class MigratePrivateStorage extends Command
{
    protected $signature = 'masar:migrate-private-storage {--dry-run : Count and check the files that would move without changing anything}';

    protected $description = 'نقل مستندات الموظفين وصورهم من التخزين العام إلى التخزين الخاص';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $paths = collect()
            ->merge(Document::withoutGlobalScopes()->whereNotNull('file_path')->pluck('file_path'))
            ->merge(Employee::withoutGlobalScopes()->whereNotNull('profile_photo_path')->pluck('profile_photo_path'))
            ->filter()
            ->unique();

        $moved = $deduped = $conflicts = $missing = 0;

        foreach ($paths as $path) {
            $localExists = Storage::disk('local')->exists($path);
            $publicExists = Storage::disk('public')->exists($path);

            if (!$publicExists) {
                if (!$localExists) {
                    $missing++;
                    $this->warn("غير موجود على أي قرص: {$path}");
                }
                continue;
            }

            if ($localExists) {
                // Both disks hold the path. Remove the public copy only when
                // it is byte-identical to the private one; otherwise keep
                // both (the private copy wins at serve time) and warn.
                if (md5((string) Storage::disk('local')->get($path)) === md5((string) Storage::disk('public')->get($path))) {
                    if (!$dryRun) {
                        Storage::disk('public')->delete($path);
                    }
                    $deduped++;
                } else {
                    $conflicts++;
                    $this->warn("تعارض محتوى بين القرصين، تُرك كلا الملفين: {$path}");
                }
                continue;
            }

            if (!$dryRun) {
                $contents = Storage::disk('public')->get($path);
                Storage::disk('local')->put($path, $contents);
                $copied = Storage::disk('local')->exists($path)
                    && md5((string) Storage::disk('local')->get($path)) === md5($contents);
                if (!$copied) {
                    $conflicts++;
                    $this->error("فشل التحقق من النسخة الخاصة، بقي الملف العام كما هو: {$path}");
                    continue;
                }
                Storage::disk('public')->delete($path);
            }
            $moved++;
        }

        $verb = $dryRun ? 'سيتم نقله' : 'تم نقله';
        $this->info("{$verb}: {$moved} ملف. حذف تكرار عام مطابق: {$deduped}. تعارضات أبقيت كما هي: {$conflicts}. غير موجود: {$missing}.");
        if ($dryRun) {
            $this->comment('وضع المعاينة فقط؛ لم يتغير أي ملف. شغّل بدون --dry-run بعد أخذ نسخة احتياطية موثوقة.');
        } elseif ($moved > 0 || $deduped > 0) {
            $this->comment('تحقق أن الملفات القديمة لم تعد متاحة عبر روابط public المباشرة، ثم احذف رابط public/storage من السيرفر.');
        }

        return $conflicts > 0 ? self::FAILURE : self::SUCCESS;
    }
}
