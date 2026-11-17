<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class OwnerDatabaseUpdateService
{
    private const LOCK_FILE = 'owner-database-updates.lock';

    public function status(): array
    {
        try {
            $ran = DB::table('migrations')
                ->orderBy('migration')
                ->pluck('migration')
                ->all();
            $lastBatch = (int) DB::table('migrations')->max('batch');
        } catch (Throwable) {
            return [
                'available' => false,
                'error' => __('تعذر قراءة سجل migrations. راجع قاعدة البيانات أو نفذ الإعداد الأساسي من بيئة آمنة.'),
                'ran_count' => 0,
                'last_batch' => null,
                'pending' => [],
                'next' => null,
            ];
        }

        $migrations = collect(File::files(database_path('migrations')))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->map(fn ($file) => $file->getBasename('.php'))
            ->sort()
            ->values();

        $pending = $migrations
            ->diff($ran)
            ->values()
            ->map(fn (string $name) => [
                'name' => $name,
                'path' => 'database/migrations/'.$name.'.php',
            ]);

        return [
            'available' => true,
            'error' => null,
            'ran_count' => count($ran),
            'last_batch' => $lastBatch,
            'pending' => $pending->all(),
            'next' => $pending->first(),
        ];
    }

    public function runNext(): array
    {
        $lock = $this->acquireLock();
        if (! $lock) {
            return $this->failure('locked', __('هناك تحديث قاعدة بيانات قيد التنفيذ بالفعل. انتظر حتى ينتهي قبل المحاولة مرة أخرى.'));
        }

        try {
            $status = $this->status();
            if (! ($status['available'] ?? false)) {
                return $this->failure('unavailable', $status['error'] ?? __('تعذر قراءة حالة قاعدة البيانات.'));
            }

            $next = $status['next'] ?? null;
            if (! $next) {
                return $this->failure('no_pending', __('لا توجد تحديثات معلقة حاليًا.'));
            }

            $exitCode = Artisan::call('migrate', [
                '--path' => $next['path'],
                '--force' => true,
            ]);
            $output = trim((string) Artisan::output());

            $after = $this->status();
            $stillPending = collect($after['pending'] ?? [])->contains('name', $next['name']);

            if ($exitCode !== 0 || ! ($after['available'] ?? false) || $stillPending) {
                return $this->failure(
                    'failed',
                    __('فشل تنفيذ التحديث :migration. راجع نسخة قاعدة البيانات والتحديث قبل المحاولة مرة أخرى.', ['migration' => $next['name']]),
                    [
                        'migration' => $next['name'],
                        'pending_before' => count($status['pending'] ?? []),
                        'pending_after' => count($after['pending'] ?? []),
                        'exit_code' => $exitCode,
                        'output' => $output,
                    ]
                );
            }

            return [
                'ok' => true,
                'code' => 'completed',
                'message' => __('تم تنفيذ التحديث :migration بنجاح.', ['migration' => $next['name']]),
                'migration' => $next['name'],
                'pending_before' => count($status['pending'] ?? []),
                'pending_after' => count($after['pending'] ?? []),
                'exit_code' => $exitCode,
                'output' => $output,
            ];
        } catch (Throwable $e) {
            report($e);

            return $this->failure('exception', __('حدث خطأ أثناء تنفيذ التحديث. لم يتم تنفيذ أي تحديث إضافي.'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return resource|null */
    private function acquireLock()
    {
        $path = storage_path('framework/'.self::LOCK_FILE);
        $handle = @fopen($path, 'c');

        if (! $handle) {
            return null;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        ftruncate($handle, 0);
        fwrite($handle, now()->toIso8601String().' '.getmypid());
        fflush($handle);

        return $handle;
    }

    private function failure(string $code, string $message, array $details = []): array
    {
        return array_merge([
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'migration' => null,
            'pending_before' => null,
            'pending_after' => null,
            'exit_code' => null,
            'output' => null,
        ], $details);
    }
}
