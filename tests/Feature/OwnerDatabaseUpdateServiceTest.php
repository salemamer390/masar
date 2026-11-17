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
        '2099_01_01_000003_owner_update_service_failure_test',
        '2099_01_01_000004_owner_update_service_lock_test',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        if (!\Schema::hasTable('migrations')) {
            Artisan::call('migrate');
        }
        $this->removeTestMigrations();
    }

    protected function tearDown(): void
    {
        $this->removeTestMigrations();
        parent::tearDown();
    }

    public function test_it_runs_only_the_oldest_pending_migration_and_verifies_it_left_pending_state(): void
    {
        $this->createTestMigration($this->testMigrations[0]);
        $this->createTestMigration($this->testMigrations[1]);
        $service = app(OwnerDatabaseUpdateService::class);

        $status = $service->status();
        $this->assertSame($this->testMigrations[0], $status['next']['name']);

        $result = $service->runNext();

        $this->assertTrue($result['ok']);
        $this->assertSame($this->testMigrations[0], $result['migration']);
        $this->assertSame(2, $result['pending_before']);
        $this->assertSame(1, $result['pending_after']);
        $this->assertDatabaseHas('migrations', ['migration' => $this->testMigrations[0]]);
        $this->assertDatabaseMissing('migrations', ['migration' => $this->testMigrations[1]]);

        $after = $service->status();
        $this->assertSame($this->testMigrations[1], $after['next']['name']);
    }

    public function test_it_reports_failure_when_the_migration_fails(): void
    {
        $this->createTestMigration($this->testMigrations[2], valid: false);
        $service = app(OwnerDatabaseUpdateService::class);

        $result = $service->runNext();

        $this->assertFalse($result['ok']);
        $this->assertContains($result['code'], ['failed', 'exception']);
        $this->assertDatabaseMissing('migrations', ['migration' => $this->testMigrations[2]]);
    }

    public function test_it_refuses_to_run_while_the_lock_is_held(): void
    {
        $this->createTestMigration($this->testMigrations[3]);
        $service = app(OwnerDatabaseUpdateService::class);
        $lockPath = storage_path('framework/owner-database-updates.lock');
        $lock = fopen($lockPath, 'c');
        $this->assertTrue(flock($lock, LOCK_EX | LOCK_NB));

        $result = $service->runNext();

        flock($lock, LOCK_UN);
        fclose($lock);

        $this->assertFalse($result['ok']);
        $this->assertSame('locked', $result['code']);
        $this->assertDatabaseMissing('migrations', ['migration' => $this->testMigrations[3]]);
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
