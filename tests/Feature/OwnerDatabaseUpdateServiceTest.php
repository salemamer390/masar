<?php

namespace Tests\Feature;

use App\Services\OwnerDatabaseUpdateService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OwnerDatabaseUpdateServiceTest extends TestCase
{
    private array $testMigrations = [
        '2099_01_01_000001_owner_update_service_first_test',
        '2099_01_01_000002_owner_update_service_second_test',
        '2099_01_01_000003_owner_update_service_unreviewed_test',
        '2099_01_01_000004_owner_update_service_changed_test',
        '2099_01_01_000005_owner_update_service_failure_test',
        '2099_01_01_000006_owner_update_service_marker_test',
        '2099_01_01_000007_owner_update_service_lock_test',
    ];

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        if (!\Schema::hasTable('migrations')) {
            Artisan::call('migrate');
        }
        $this->manifestPath = storage_path('framework/testing-owner-reviewed-migrations-'.getmypid().'.json');
        config()->set('owner.reviewed_migrations_path', $this->manifestPath);
        $this->writeManifest([]);
        $this->removeTestMigrations();
        $this->removeAttemptMarker();
    }

    protected function tearDown(): void
    {
        $this->removeTestMigrations();
        $this->removeAttemptMarker();
        @unlink($this->manifestPath);
        parent::tearDown();
    }

    public function test_it_runs_only_the_oldest_pending_reviewed_migration_and_clears_the_marker(): void
    {
        $this->createTestMigration($this->testMigrations[0]);
        $this->createTestMigration($this->testMigrations[1]);
        $this->authorizeMigrations([$this->testMigrations[0], $this->testMigrations[1]]);
        $service = app(OwnerDatabaseUpdateService::class);

        $status = $service->status();
        $this->assertSame($this->testMigrations[0], $status['next']['name']);
        $this->assertCount(2, $status['pending']);
        $this->assertCount(0, $status['blocked']);

        $result = $service->runNext();

        $this->assertTrue($result['ok']);
        $this->assertSame($this->testMigrations[0], $result['migration']);
        $this->assertSame(2, $result['pending_before']);
        $this->assertSame(1, $result['pending_after']);
        $this->assertDatabaseHas('migrations', ['migration' => $this->testMigrations[0]]);
        $this->assertDatabaseMissing('migrations', ['migration' => $this->testMigrations[1]]);
        $this->assertFileDoesNotExist($this->attemptPath());

        $after = $service->status();
        $this->assertSame($this->testMigrations[1], $after['next']['name']);
    }

    public function test_it_blocks_an_unreviewed_migration_by_default(): void
    {
        $this->createTestMigration($this->testMigrations[2]);
        $service = app(OwnerDatabaseUpdateService::class);

        $status = $service->status();
        $this->assertCount(0, $status['pending']);
        $this->assertSame('unreviewed', $status['blocked'][0]['reason']);

        $result = $service->runNext();

        $this->assertFalse($result['ok']);
        $this->assertSame('no_reviewed_pending', $result['code']);
        $this->assertDatabaseMissing('migrations', ['migration' => $this->testMigrations[2]]);
        $this->assertFileDoesNotExist($this->attemptPath());
    }

    public function test_it_blocks_a_migration_whose_content_changed_after_review(): void
    {
        $this->createTestMigration($this->testMigrations[3]);
        $this->authorizeMigrations([$this->testMigrations[3]]);
        file_put_contents(database_path('migrations/'.$this->testMigrations[3].'.php'), "\n// changed after review\n", FILE_APPEND);
        $service = app(OwnerDatabaseUpdateService::class);

        $status = $service->status();
        $this->assertCount(0, $status['pending']);
        $this->assertSame('changed', $status['blocked'][0]['reason']);

        $result = $service->runNext();

        $this->assertFalse($result['ok']);
        $this->assertSame('no_reviewed_pending', $result['code']);
        $this->assertDatabaseMissing('migrations', ['migration' => $this->testMigrations[3]]);
        $this->assertFileDoesNotExist($this->attemptPath());
    }

    public function test_failed_attempt_leaves_a_durable_marker_that_blocks_retry(): void
    {
        $this->createTestMigration($this->testMigrations[4], valid: false);
        $this->authorizeMigrations([$this->testMigrations[4]]);
        $service = app(OwnerDatabaseUpdateService::class);

        $result = $service->runNext();

        $this->assertFalse($result['ok']);
        $this->assertSame('failed', $result['code']);
        $this->assertDatabaseMissing('migrations', ['migration' => $this->testMigrations[4]]);
        $marker = json_decode(file_get_contents($this->attemptPath()), true);
        $this->assertSame('failed', $marker['status']);
        $this->assertSame($this->testMigrations[4], $marker['migration']);

        $retry = $service->runNext();

        $this->assertFalse($retry['ok']);
        $this->assertSame('manual_review_required', $retry['code']);
        $this->assertDatabaseMissing('migrations', ['migration' => $this->testMigrations[4]]);
    }

    public function test_a_running_attempt_marker_blocks_execution_as_uncertain(): void
    {
        $this->createTestMigration($this->testMigrations[5]);
        $this->authorizeMigrations([$this->testMigrations[5]]);
        file_put_contents($this->attemptPath(), json_encode([
            'status' => 'running',
            'migration' => $this->testMigrations[5],
            'started_at' => now()->toIso8601String(),
        ]));
        $service = app(OwnerDatabaseUpdateService::class);

        $status = $service->status();
        $this->assertSame('running', $status['attempt']['status']);

        $result = $service->runNext();

        $this->assertFalse($result['ok']);
        $this->assertSame('manual_review_required', $result['code']);
        $this->assertDatabaseMissing('migrations', ['migration' => $this->testMigrations[5]]);
    }

    public function test_it_refuses_to_run_while_the_lock_is_held(): void
    {
        $this->createTestMigration($this->testMigrations[6]);
        $this->authorizeMigrations([$this->testMigrations[6]]);
        $service = app(OwnerDatabaseUpdateService::class);
        $lockPath = storage_path('framework/owner-database-updates.lock');
        $lock = fopen($lockPath, 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        $result = $service->runNext();

        flock($lock, LOCK_UN);
        fclose($lock);

        $this->assertFalse($result['ok']);
        $this->assertSame('locked', $result['code']);
        $this->assertDatabaseMissing('migrations', ['migration' => $this->testMigrations[6]]);
        $this->assertFileDoesNotExist($this->attemptPath());
    }

    private function createTestMigration(string $name, bool $valid = true): void
    {
        if (! $valid) {
            $contents = <<<'MIGRATION'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('Expected owner update service test failure.');
    }

    public function down(): void
    {
    }
};
MIGRATION;
        } else {
            $contents = <<<'MIGRATION'
<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
    }

    public function down(): void
    {
    }
};
MIGRATION;
        }

        file_put_contents(database_path('migrations/'.$name.'.php'), $contents);
    }

    private function authorizeMigrations(array $names): void
    {
        $migrations = [];
        foreach ($names as $name) {
            $migrations[$name] = hash_file('sha256', database_path('migrations/'.$name.'.php'));
        }
        $this->writeManifest($migrations);
    }

    private function writeManifest(array $migrations): void
    {
        file_put_contents($this->manifestPath, json_encode([
            'version' => 1,
            'migrations' => $migrations,
        ], JSON_PRETTY_PRINT));
    }

    private function attemptPath(): string
    {
        return storage_path('framework/owner-database-updates-attempt.json');
    }

    private function removeAttemptMarker(): void
    {
        @unlink($this->attemptPath());
    }

    private function removeTestMigrations(): void
    {
        foreach ($this->testMigrations as $migration) {
            @unlink(database_path('migrations/'.$migration.'.php'));
            if (\Schema::hasTable('migrations')) {
                DB::table('migrations')->where('migration', $migration)->delete();
            }
        }
    }
}
