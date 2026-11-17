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
        $checks = [];

        try {
            $ran = DB::table('migrations')
                ->orderBy('migration')
                ->pluck('migration')
                ->all();
            $lastBatch = (int) DB::table('migrations')->max('batch');
            $checks[] = $this->readinessCheck(
                'migrations_table',
                __('سجل migrations'),
                'ok',
                __('السجل متاح ويمكن مقارنة الملفات المنفذة بالمعلقة.')
            );
        } catch (Throwable) {
            $checks[] = $this->readinessCheck(
                'migrations_table',
                __('سجل migrations'),
                'failed',
                __('تعذر قراءة السجل من قاعدة البيانات.')
            );

            $checks = array_merge($checks, $this->environmentReadinessChecks($attempt));

            return $this->unavailableStatus(
                __('تعذر قراءة سجل migrations. راجع قاعدة البيانات أو نفذ الإعداد الأساسي من بيئة آمنة.'),
                $attempt,
                checks: $checks
            );
        }

        if (count($ran) === 0) {
            $checks[] = $this->readinessCheck(
                'migrations_baseline',
                __('الإعداد الأساسي'),
                'failed',
                __('السجل فارغ؛ هذه الصفحة لا تستخدم لإعداد قاعدة بيانات جديدة.')
            );

            $checks = array_merge($checks, $this->environmentReadinessChecks($attempt));

            return $this->unavailableStatus(
                __('هذه الصفحة لا تستخدم لإعداد قاعدة بيانات جديدة. نفذ الإعداد الأساسي من بيئة آمنة أولًا.'),
                $attempt,
                checks: $checks
            );
        }

        $manifest = $this->loadReviewedManifest();
        $checks[] = $this->readinessCheck(
            'reviewed_manifest',
            __('قائمة التحديثات المراجعة'),
            $manifest['ok'] ? 'ok' : 'failed',
            $manifest['ok']
                ? __('الملف موجود وصالح، والتنفيذ مقيد ببصمات SHA-256 المسجلة فيه.')
                : $manifest['error']
        );
        if (! $manifest['ok']) {
            $checks = array_merge($checks, $this->environmentReadinessChecks($attempt));

            return $this->unavailableStatus(
                $manifest['error'],
                $attempt,
                count($ran),
                $lastBatch,
                $checks
            );
        }

        $migrationsRoot = realpath(database_path('migrations'));
        $checks[] = $this->readinessCheck(
            'migrations_directory',
            __('مجلد migrations الرسمي'),
            $migrationsRoot === false ? 'failed' : 'ok',
            $migrationsRoot === false
                ? __('تعذر تحديد المسار الرسمي.')
                : __('المسار الرسمي محدد، وأي رابط أو ملف خارج هذا المسار محظور.')
        );
        if ($migrationsRoot === false) {
            return $this->unavailableStatus(
                __('تعذر تحديد مجلد migrations الرسمي، لذلك التنفيذ محظور افتراضيًا.'),
                $attempt,
                count($ran),
                $lastBatch,
                $checks
            );
        }

        $checks = array_merge($checks, $this->environmentReadinessChecks($attempt));

        $migrations = collect(File::files($migrationsRoot))
            ->filter(fn ($file) => $file->getExtension() === 'php' && $file->isFile())
            ->map(fn ($file) => ['file' => $file, 'name' => $file->getBasename('.php')])
            ->sortBy('name')
            ->values();

        $eligible = [];
        $blocked = [];
        $orderedPending = [];

        foreach ($migrations as $migrationFile) {
            $name = $migrationFile['name'];
            if (in_array($name, $ran, true)) {
                continue;
            }

            $file = $migrationFile['file'];
            $relativePath = 'database/migrations/'.$name.'.php';
            $absolutePath = $migrationsRoot.DIRECTORY_SEPARATOR.$name.'.php';
            $realPath = $file->getRealPath();
            $entry = [
                'name' => $name,
                'path' => $relativePath,
                'eligible' => false,
            ];

            if ($file->isLink() || ! is_string($realPath) || ! str_starts_with($realPath, $migrationsRoot.DIRECTORY_SEPARATOR)) {
                $entry['reason'] = 'outside_path';
                $blocked[] = $entry;
                $orderedPending[] = $entry;
                continue;
            }

            if (! array_key_exists($name, $manifest['migrations'])) {
                $entry['reason'] = 'unreviewed';
                $blocked[] = $entry;
                $orderedPending[] = $entry;
                continue;
            }

            $actualHash = @hash_file('sha256', $absolutePath);
            if ($actualHash === false) {
                $entry['reason'] = 'unreadable';
                $blocked[] = $entry;
                $orderedPending[] = $entry;
                continue;
            }

            if (! hash_equals(strtolower($manifest['migrations'][$name]), strtolower($actualHash))) {
                $entry['reason'] = 'changed';
                $blocked[] = $entry;
                $orderedPending[] = $entry;
                continue;
            }

            $entry['eligible'] = true;
            $entry['sha256'] = $actualHash;
            $eligible[] = [
                'name' => $entry['name'],
                'path' => $entry['path'],
                'sha256' => $entry['sha256'],
            ];
            $orderedPending[] = $entry;
        }

        $next = null;
        $blocking = null;
        $firstPending = $orderedPending[0] ?? null;
        if ($firstPending) {
            if ($firstPending['eligible']) {
                $next = [
                    'name' => $firstPending['name'],
                    'path' => $firstPending['path'],
                    'sha256' => $firstPending['sha256'],
                ];
            } else {
                $blocking = [
                    'name' => $firstPending['name'],
                    'path' => $firstPending['path'],
                    'reason' => $firstPending['reason'],
                ];
            }
        }

        return [
            'available' => true,
            'error' => null,
            'ran_count' => count($ran),
            'last_batch' => $lastBatch,
            'pending' => $eligible,
            'blocked' => $blocked,
            'next' => $next,
            'blocking' => $blocking,
            'attempt' => $attempt,
            'checks' => $checks,
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

            $blocking = $status['blocking'] ?? null;
            if ($blocking) {
                return $this->failure(
                    'blocked_prerequisite',
                    __('يوجد تحديث سابق :migration غير مصرح به أو يحتاج مراجعة. لن يتم تجاوزه لتنفيذ تحديث لاحق.', ['migration' => $blocking['name']]),
                    [
                        'migration' => $blocking['name'],
                        'blocked_reason' => $blocking['reason'] ?? null,
                    ]
                );
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

    /** @return array<int, array{key: string, label: string, state: string, detail: string}> */
    private function environmentReadinessChecks(?array $attempt): array
    {
        $storagePath = storage_path('framework');
        $storageReady = is_dir($storagePath) && is_writable($storagePath);
        $rawLimit = ini_get('max_execution_time');
        $seconds = is_numeric($rawLimit) ? (int) $rawLimit : null;
        $limitDetail = match (true) {
            $seconds === 0 => __('غير محدود في البيئة الحالية.'),
            $seconds !== null => __(':seconds ثانية. نفذ تحديثات صغيرة مجربة فقط داخل هذا الحد.', ['seconds' => $seconds]),
            default => __('تعذر قراءة حد تنفيذ PHP الحالي.'),
        };

        return [
            $this->readinessCheck(
                'storage_writable',
                __('تخزين علامة الإيقاف'),
                $storageReady ? 'ok' : 'failed',
                $storageReady
                    ? __('مجلد storage/framework قابل للكتابة ويمكنه حفظ علامة الإيقاف.')
                    : __('مجلد storage/framework غير قابل للكتابة؛ يجب إصلاحه قبل التنفيذ.')
            ),
            $this->readinessCheck(
                'attempt_marker',
                __('علامة المحاولة السابقة'),
                $attempt ? 'warning' : 'ok',
                $attempt
                    ? __('يوجد ملف إيقاف يحتاج مراجعة يدوية قبل أي تنفيذ جديد.')
                    : __('لا توجد محاولة سابقة معلقة.')
            ),
            $this->readinessCheck(
                'execution_time',
                __('حد تنفيذ PHP'),
                ($seconds !== null && $seconds > 0 && $seconds <= 60) ? 'warning' : 'ok',
                $limitDetail
            ),
            $this->readinessCheck(
                'manual_backup',
                __('النسخة الاحتياطية الخارجية'),
                'warning',
                __('الصفحة لا تنشئ نسخة احتياطية ولا تستطيع التحقق منها؛ احفظ Export كامل من phpMyAdmin قبل التنفيذ.')
            ),
        ];
    }

    /** @return array{key: string, label: string, state: string, detail: string} */
    private function readinessCheck(string $key, string $label, string $state, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'detail' => $detail,
        ];
    }

    private function unavailableStatus(string $error, ?array $attempt = null, int $ranCount = 0, ?int $lastBatch = null, array $checks = []): array
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
            'checks' => $checks,
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
