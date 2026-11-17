<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repair hosts whose server default engine created the new tables as
     * MyISAM. The engine conversion preserves rows; missing foreign keys are
     * then added without changing migration history or deleting data.
     */
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        foreach (['fingerprint_device_commands', 'tour_steps'] as $table) {
            $this->requireTable($table);
            $this->ensureInnoDb($table);
        }

        $this->ensureForeignKey('device_id', 'fingerprint_devices', 'CASCADE');
        $this->ensureForeignKey('created_by', 'users', 'SET NULL');
    }

    /**
     * Rolling forward repaired an unsafe state; rolling back must not restore
     * MyISAM or remove database integrity constraints.
     */
    public function down(): void
    {
        // Intentionally no-op.
    }

    private function requireTable(string $table): void
    {
        if (! Schema::hasTable($table)) {
            throw new RuntimeException("Required table [{$table}] is missing; review the earlier migration state before running this repair.");
        }
    }

    private function ensureInnoDb(string $table): void
    {
        $row = DB::selectOne(
            'SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );

        if (strtoupper((string) ($row->engine ?? '')) !== 'INNODB') {
            DB::statement("ALTER TABLE `{$table}` ENGINE=InnoDB");
        }
    }

    private function ensureForeignKey(string $column, string $referencedTable, string $deleteRule): void
    {
        $table = 'fingerprint_device_commands';

        if (! Schema::hasColumn($table, $column)) {
            throw new RuntimeException("Required column [{$table}.{$column}] is missing; review the earlier migration state before running this repair.");
        }

        $constraints = DB::select(
            'SELECT k.CONSTRAINT_NAME AS name, k.REFERENCED_TABLE_NAME AS referenced_table, k.REFERENCED_COLUMN_NAME AS referenced_column, r.DELETE_RULE AS delete_rule
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.CONSTRAINT_SCHEMA = DATABASE()
               AND k.TABLE_NAME = ?
               AND k.COLUMN_NAME = ?
               AND k.REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        );

        foreach ($constraints as $constraint) {
            if (
                $constraint->referenced_table === $referencedTable
                && $constraint->referenced_column === 'id'
                && strtoupper((string) $constraint->delete_rule) === $deleteRule
            ) {
                return;
            }
        }

        if ($constraints !== []) {
            throw new RuntimeException("Unexpected foreign key on [{$table}.{$column}]; review it manually instead of replacing it automatically.");
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column, $referencedTable, $deleteRule, $table): void {
            $foreign = $blueprint
                ->foreign($column, "{$table}_{$column}_foreign")
                ->references('id')
                ->on($referencedTable);

            if ($deleteRule === 'CASCADE') {
                $foreign->cascadeOnDelete();
            } elseif ($deleteRule === 'SET NULL') {
                $foreign->nullOnDelete();
            }
        });
    }
};
