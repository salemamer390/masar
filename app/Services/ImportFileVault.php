<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Private copy of every workbook uploaded to the import wizard, kept so the
 * platform owner can study different file formats and sheet names.
 *
 * There is deliberately no route, page or link for it. The owner browses the
 * folder from the hosting file manager (storage/app/private/import-vault) and
 * downloads files directly, so they are stored as plain files with readable
 * names: DATE_TIME_COMPANYNAME_HASH_ORIGINALNAME. A deny-all .htaccess guards
 * the folder against browser access on hosts where storage/ sits under the
 * web root.
 *
 * One copy per company and file content: the wizard uploads the same file at
 * each step, which must not create duplicates. A failure here never blocks
 * the import itself.
 */
class ImportFileVault
{
    public static function directory(): string
    {
        return storage_path('app/private/import-vault');
    }

    public function capture(UploadedFile $file, ?User $user): void
    {
        try {
            $path = (string) $file->getRealPath();
            if ($path === '' || !is_file($path) || !is_readable($path)) return;
            $hash = substr((string) hash_file('sha256', $path), 0, 10);
            $company = $this->slug((string) ($user?->company?->name ?: 'company-' . (int) ($user?->company_id ?? 0)), 30);

            $dir = self::directory();
            $this->prepareDirectory($dir);
            if (glob($dir . '/*_' . $company . '_' . $hash . '_*')) return;

            $name = $this->slug((string) $file->getClientOriginalName(), 80, true) ?: 'workbook';
            $target = $dir . '/' . now()->format('Y-m-d_Hi') . '_' . $company . '_' . $hash . '_' . $name;
            copy($path, $target);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function slug(string $value, int $max, bool $fromEnd = false): string
    {
        $value = trim((string) preg_replace('/[^\p{L}\p{N}._-]+/u', '_', $value), '._');

        return $fromEnd ? mb_substr($value, -$max) : mb_substr($value, 0, $max);
    }

    private function prepareDirectory(string $dir): void
    {
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        if (!is_file($dir . '/.htaccess')) {
            file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
        }
        if (!is_file($dir . '/index.html')) file_put_contents($dir . '/index.html', '');
    }
}
