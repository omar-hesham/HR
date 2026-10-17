<?php

namespace App\Services\Operations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Portable logical dump: schema is recreated by the versioned Laravel migrations. */
final class DatabaseSnapshot
{
    /** @return array<string,list<array<string,mixed>>> */
    public function capture(): array
    {
        $tables = $this->tables();
        $snapshot = [];
        foreach ($tables as $table) {
            if (str_starts_with($table, 'sqlite_')) {
                continue;
            }
            $rows = DB::table($table)->get()->map(fn ($row): array => (array) $row)->all();
            foreach ($rows as &$row) {
                ksort($row);
            }
            unset($row);
            usort($rows, fn (array $a, array $b): int => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));
            $snapshot[$table] = $rows;
        }

        return $snapshot;
    }

    /** @param array<string,list<array<string,mixed>>> $snapshot */
    public function load(array $snapshot): void
    {
        $provided = array_keys($snapshot);
        sort($provided);
        if ($provided !== $this->tables()) {
            throw new RuntimeException('restore_table_set_mismatch');
        }
        Schema::disableForeignKeyConstraints();
        try {
            foreach (array_keys($snapshot) as $table) {
                DB::table($table)->delete();
            }
            foreach ($snapshot as $table => $rows) {
                foreach (array_chunk($rows, 50) as $chunk) {
                    DB::table($table)->insert($chunk);
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /** @return list<string> */
    private function tables(): array
    {
        $schema = DB::connection()->getDriverName() === 'sqlite' ? 'main' : DB::connection()->getDatabaseName();
        $tables = array_values(array_filter(Schema::getTableListing($schema, false), fn (string $table): bool => ! str_starts_with($table, 'sqlite_')));
        foreach ($tables as $table) {
            if (! preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
                throw new RuntimeException('restore_table_name_invalid');
            }
        }
        sort($tables);

        return $tables;
    }

    /** @param array<string,list<array<string,mixed>>> $snapshot */
    public function hash(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR));
    }
}
