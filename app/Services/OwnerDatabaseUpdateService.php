<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

class OwnerDatabaseUpdateService
{
    private const LOCK_FILE = 'owner-database-updates.lock';
    private const ATTEMPT_FILE = 'owner-database-updates-attempt.json';

    public function status(): array
    {
        $attempt = $this->attemptState();

        try {
            $ran = DB::table('migrations')
                ->orderBy('migration')
                ->pluck('migration')
                ->all();
            $lastBatch = (int) DB::table('migrations')->max('batch');
        } catch (Throwable) {
            return $this->unavailableStatus(
                __('تعذر قراءة سجل migrations. راجع قاعدة البيانات أو نفذ الإعداد الأساسي من بيئة آمنة.'),
                $attempt
            );
        }

        if (count($ran) === 0) {
            return $this->unavailableStatus(
                __('هذه الصفحة لا تستخدم لإعداد قاعدة بيانات جديدة. نفذ الإعداد الأساسي من بيئة آمنة أولًا.'),
                $attempt
            );
        }

        $manifest = $this->loadReviewedManifest();
        if (! $manifest['ok']) {
            return $this->unavailableStatus($manifest['error'], $attempt, count($ran), $lastBatch);
        }

        $migrations = collect(File::files(database_path('migrations')))
            ->filter(fn ($file) => $file->getExtension() === 'php')
            ->map(fn ($file) => $file->getBasename('.php'))
            ->sort()
            ->values();

        $eligible = [];
        $blocked = [];

        foreach ($migrations->diff($ran)->values() as $name) {
            $relativePath = 'database/migrations/'.$name.'.php';
            $absolutePath = database_path('migrations/'.$name.'.php');

            if (! array_key_exists($name, $manifest['migrations'])) {
                $blocked[] = [
                    'name' => $name,
                    'path' => $relativePath,
                    'reason' => 'unreviewed',
                ];
                continue;
            }

            $actualHash = @hash_file('sha256', $absolutePath);
            if ($actualHash === false) {
                $blocked[] = [
                    'name' => $name,
                    'path' => $relativePath,
                    'reason' => 'unreadable',
                ];
                continue;
            }

            if (! hash_equals(strtolower($manifest['migrations'][$name]), strtolower($actualHash))) {
                $blocked[] = [
                    'name' => $name,
                    'path' => $relativePath,
                    'reason' => 'changed',
                ];
                continue;
            }

            $eligible[] = [
                'name' => $name,
                'path' => $relativePath,
                'sha256' => $actualHash,
            ];
        }

        return [
            'available' => true,
            'error' => null,
            'ran_count' => count($ran),
            'last_batch' => $lastBatch,
            'pending' => $eligible,
            'blocked' => $blocked,
            'next' => $eligible[0] ?? null,
            'attempt' => $attempt,
        ];
    }

    public function runNext(): array
    {
        $lock = $this->acquireLock();
        if (! $lock) {
            return $this->failure('locked', __('هناك تحديث قاعدة بيانات قيد التنفيذ بالفعل. انتظر حتى ينتهي قبل المحاولة مرة أخرى.'));
        }

        try {
            $existingAttempt = $this->attemptState();
            if ($existingAttempt) {
                return $this->failure(
                    'manual_review_required',
                    $this->manualReviewMessage($existingAttempt),
                    [
                        'migration' => $existingAttempt['migration'] ?? null,
                        'attempt_status' => $existingAttempt['status'] ?? 'uncertain',
                    ]
                );
            }

            $status = $this->status();
            if (! ($status['available'] ?? false)) {
                return $this->failure('unavailable', $status['error'] ?? __('تعذر قراءة حالة قاعدة البيانات.'));
            }

            $next = $status['next'] ?? null;
            if (! $next) {
                if (count($status['blocked'] ?? []) > 0) {
                    return $this->failure(
                        'no_reviewed_pending',
                        __('لا توجد تحديثات مصرح بها للتنفيذ. التحديثات غير المراجعة أو المتغيرة محظورة من هذه الصفحة.')
                    );
                }

                return $this->failure('no_pending', __('لا توجد تحديثات معلقة حاليًا.'));
            }

            if (! $this->writeAttemptMarker($next, 'running')) {
                return $this->failure(
                    'attempt_marker_unavailable',
                    __('تعذر تسجيل محاولة التحديث قبل التنفيذ، لذلك لم يبدأ التحديث. راجع صلاحيات التخزين أولًا.'),
                    ['migration' => $next['name']]
                );
            }

            try {
                $exitCode = Artisan::call('migrate', [
                    '--path' => $next['path'],
                    '--force' => true,
                ]);
                $output = trim((string) Artisan::output());
            } catch (Throwable $e) {
                report($e);
                $this->writeAttemptMarker($next, 'failed', get_class($e));

                return $this->failure(
                    'failed',
                    __('فشل تنفيذ التحديث :migration. التنفيذ متوقف الآن حتى مراجعة قاعدة البيانات يدويًا.', ['migration' => $next['name']]),
                    [
                        'migration' => $next['name'],
                        'pending_before' => count($status['pending'] ?? []),
                    ]
                );
            }

            $after = $this->status();
            $stillPending = collect($after['pending'] ?? [])->contains('name', $next['name']);

            if ($exitCode !== 0 || ! ($after['available'] ?? false) || $stillPending) {
                $this->writeAttemptMarker($next, 'failed', 'exit_code_'.$exitCode);

                return $this->failure(
                    'failed',
                    __('فشل تنفيذ التحديث :migration أو لم يتضح اكتماله. التنفيذ متوقف الآن حتى مراجعة قاعدة البيانات يدويًا.', ['migration' => $next['name']]),
                    [
                        'migration' => $next['name'],
                        'pending_before' => count($status['pending'] ?? []),
                        'pending_after' => count($after['pending'] ?? []),
                        'exit_code' => $exitCode,
                        'output' => $output,
                    ]
                );
            }

            if (! $this->clearAttemptMarker()) {
                return $this->failure(
                    'manual_review_required',
                    __('تم تنفيذ التحديث :migration لكن تعذر مسح علامة المحاولة. التنفيذ متوقف حتى مراجعة الحالة يدويًا.', ['migration' => $next['name']]),
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

            return $this->failure('exception', __('حدث خطأ أثناء فحص التحديث. لم يبدأ أي تحديث إضافي.'));
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

    private function loadReviewedManifest(): array
    {
        $path = (string) config('owner.reviewed_migrations_path');

        try {
            if (! File::exists($path)) {
                return [
                    'ok' => false,
                    'error' => __('ملف التحديثات المراجعة غير موجود، لذلك التنفيذ محظور افتراضيًا.'),
                    'migrations' => [],
                ];
            }

            $decoded = json_decode(File::get($path), true);
        } catch (Throwable) {
            $decoded = null;
        }

        $migrations = $decoded['migrations'] ?? null;
        if (! is_array($migrations)) {
            return [
                'ok' => false,
                'error' => __('ملف التحديثات المراجعة غير صالح، لذلك التنفيذ محظور افتراضيًا.'),
                'migrations' => [],
            ];
        }

        foreach ($migrations as $name => $hash) {
            if (! is_string($name) || ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/i', $hash)) {
                return [
                    'ok' => false,
                    'error' => __('ملف التحديثات المراجعة يحتوي بصمة غير صالحة، لذلك التنفيذ محظور افتراضيًا.'),
                    'migrations' => [],
                ];
            }
        }

        return ['ok' => true, 'error' => null, 'migrations' => $migrations];
    }

    private function attemptState(): ?array
    {
        $path = $this->attemptPath();
        if (! file_exists($path)) {
            return null;
        }

        $decoded = json_decode((string) @file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [
                'status' => 'uncertain',
                'migration' => null,
                'started_at' => null,
            ];
        }

        return [
            'status' => (string) ($decoded['status'] ?? 'uncertain'),
            'migration' => $decoded['migration'] ?? null,
            'started_at' => $decoded['started_at'] ?? null,
        ];
    }

    private function writeAttemptMarker(array $migration, string $status, ?string $error = null): bool
    {
        $existing = $this->attemptState();
        $payload = array_filter([
            'status' => $status,
            'migration' => $migration['name'],
            'path' => $migration['path'],
            'sha256' => $migration['sha256'] ?? null,
            'started_at' => $existing['started_at'] ?? now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'pid' => getmypid(),
            'error' => $error,
        ], fn ($value) => ! is_null($value));

        return @file_put_contents($this->attemptPath(), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
    }

    private function clearAttemptMarker(): bool
    {
        $path = $this->attemptPath();
        if (! file_exists($path)) {
            return true;
        }

        return @unlink($path) || ! file_exists($path);
    }

    private function attemptPath(): string
    {
        return storage_path('framework/'.self::ATTEMPT_FILE);
    }

    private function manualReviewMessage(array $attempt): string
    {
        $migration = $attempt['migration'] ?? __('غير معروف');

        return __('التنفيذ متوقف لأن محاولة سابقة للتحديث :migration لم تكتمل أو تحتاج مراجعة. راجع قاعدة البيانات وسجل migrations يدويًا قبل السماح بأي محاولة أخرى.', ['migration' => $migration]);
    }

    private function unavailableStatus(string $error, ?array $attempt = null, int $ranCount = 0, ?int $lastBatch = null): array
    {
        return [
            'available' => false,
            'error' => $error,
            'ran_count' => $ranCount,
            'last_batch' => $lastBatch,
            'pending' => [],
            'blocked' => [],
            'next' => null,
            'attempt' => $attempt,
        ];
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
