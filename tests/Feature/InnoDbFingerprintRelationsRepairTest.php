<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class InnoDbFingerprintRelationsRepairTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('The engine and foreign-key repair applies only to MySQL/MariaDB.');
        }
    }

    public function test_repair_converts_host_myisam_tables_and_restores_command_relationships(): void
    {
        $this->simulateHostMyisamState();
        $tourKeysBefore = DB::table('tour_steps')->orderBy('sort_order')->pluck('key')->all();

        $repaired = false;
        try {
            $this->runRepairMigration();
            $repaired = true;

            $this->assertSame('INNODB', $this->engine('fingerprint_device_commands'));
            $this->assertSame('INNODB', $this->engine('tour_steps'));
            $this->assertEqualsCanonicalizing([
                'device_id' => ['fingerprint_devices', 'id', 'CASCADE'],
                'created_by' => ['users', 'id', 'SET NULL'],
            ], $this->foreignKeys('fingerprint_device_commands'));
            $this->assertSame($tourKeysBefore, DB::table('tour_steps')->orderBy('sort_order')->pluck('key')->all());

            // A retry after a partial timeout is a no-op, not a duplicate-key error.
            $this->runRepairMigration();
            $this->assertSame('INNODB', $this->engine('fingerprint_device_commands'));
            $this->assertSame('INNODB', $this->engine('tour_steps'));
        } finally {
            if (! $repaired) {
                $this->runRepairMigration();
            }
        }
    }

    public function test_mysql_schema_builder_defaults_new_tables_to_innodb(): void
    {
        $connection = config('database.default');
        $this->assertSame('InnoDB', config("database.connections.{$connection}.engine"));

        Schema::dropIfExists('innodb_default_probe');
        Schema::create('innodb_default_probe', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
        });

        try {
            $this->assertSame('INNODB', $this->engine('innodb_default_probe'));
        } finally {
            Schema::dropIfExists('innodb_default_probe');
        }
    }

    private function simulateHostMyisamState(): void
    {
        foreach ($this->foreignKeyNames('fingerprint_device_commands') as $name) {
            DB::statement("ALTER TABLE `fingerprint_device_commands` DROP FOREIGN KEY `{$name}`");
        }

        DB::statement('ALTER TABLE `fingerprint_device_commands` ENGINE=MyISAM');
        DB::statement('ALTER TABLE `tour_steps` ENGINE=MyISAM');

        $this->assertSame('MYISAM', $this->engine('fingerprint_device_commands'));
        $this->assertSame('MYISAM', $this->engine('tour_steps'));
        $this->assertSame([], $this->foreignKeys('fingerprint_device_commands'));
    }

    private function runRepairMigration(): void
    {
        $migration = require database_path('migrations/2026_11_17_000003_repair_innodb_and_fingerprint_command_relations.php');
        $migration->up();
    }

    private function engine(string $table): string
    {
        $row = DB::selectOne(
            'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        return strtoupper((string) ($row->engine ?? ''));
    }

    /** @return array<int, string> */
    private function foreignKeyNames(string $table): array
    {
        return array_map(
            fn (object $row): string => (string) $row->name,
            DB::select(
                'SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = \'FOREIGN KEY\'',
                [$table]
            )
        );
    }

    /** @return array<string, array<int, string>> */
    private function foreignKeys(string $table): array
    {
        $rows = DB::select(
            'SELECT k.COLUMN_NAME AS column_name, k.REFERENCED_TABLE_NAME AS referenced_table, k.REFERENCED_COLUMN_NAME AS referenced_column, r.DELETE_RULE AS delete_rule
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.CONSTRAINT_SCHEMA = DATABASE()
               AND k.TABLE_NAME = ?
               AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.COLUMN_NAME',
            [$table]
        );

        $keys = [];
        foreach ($rows as $row) {
            $keys[$row->column_name] = [
                $row->referenced_table,
                $row->referenced_column,
                strtoupper((string) $row->delete_rule),
            ];
        }

        return $keys;
    }
}
