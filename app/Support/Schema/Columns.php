<?php

namespace App\Support\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;

/**
 * Portable column helpers for migrations (SQLite locally, MySQL 8.4 in production).
 */
final class Columns
{
    /**
     * Hash / identifier column compared byte-for-byte (TXT-P0-01). On MySQL it uses
     * ascii_bin; SQLite compares TEXT with BINARY collation by default.
     */
    public static function binary(Blueprint $table, string $name, int $length): ColumnDefinition
    {
        $column = $table->string($name, $length);

        if (DB::connection()->getDriverName() === 'mysql') {
            $column->charset('ascii')->collation('ascii_bin');
        }

        return $column;
    }
}
