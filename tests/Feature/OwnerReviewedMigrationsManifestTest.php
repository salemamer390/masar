<?php

namespace Tests\Feature;

use Illuminate\Support\Collection;
use Tests\TestCase;

class OwnerReviewedMigrationsManifestTest extends TestCase
{
    private const EXPECTED_MIGRATIONS = [
        '2026_11_11_000001_add_push_modes_to_fingerprint_devices',
        '2026_11_11_100000_create_tour_steps_table',
        '2026_11_15_000001_add_import_batch_id_to_imported_operational_records',
        '2026_11_17_000002_owner_updater_trial',
        '2026_11_17_000003_repair_innodb_and_fingerprint_command_relations',
        '2026_11_23_000001_remove_global_penalty_types',
    ];

    public function test_reviewed_manifest_matches_current_migration_files(): void
    {
        $manifestPath = database_path('owner-reviewed-migrations.json');
        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error());
        $this->assertSame(1, $manifest['version'] ?? null);
        $this->assertSame(self::EXPECTED_MIGRATIONS, array_keys($manifest['migrations'] ?? []));

        Collection::make($manifest['migrations'] ?? [])->each(function (string $hash, string $migration): void {
            $path = database_path("migrations/{$migration}.php");

            $this->assertFileExists($path);
            $this->assertSame(
                $hash,
                hash_file('sha256', $path),
                "The reviewed manifest must match the exact bytes of {$migration}.php."
            );
        });
    }

    public function test_reviewed_migrations_stay_lf_on_windows_checkouts(): void
    {
        $gitattributesPath = base_path('.gitattributes');
        $this->assertFileExists($gitattributesPath);

        $gitattributes = (string) file_get_contents($gitattributesPath);

        Collection::make(self::EXPECTED_MIGRATIONS)->each(function (string $migration) use ($gitattributes): void {
            $path = "database/migrations/{$migration}.php";
            $this->assertStringContainsString(
                "{$path} text eol=lf",
                $gitattributes,
                "Keep {$path} byte-identical for the owner-reviewed manifest."
            );
        });
    }
}
