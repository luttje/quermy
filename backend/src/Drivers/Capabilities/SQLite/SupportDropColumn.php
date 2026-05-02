<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLite;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLiteDriver */
trait SupportDropColumn
{
    public function dropColumn(string $database, string $table, string $columnName): void
    {
        $this->ensureConnected();
        if (!$this->sqliteVersionAtLeast('3.35.0')) {
            throw new RuntimeException(
                'DROP COLUMN requires SQLite 3.35.0 or later. '
                . 'Current version: ' . $this->sqliteVersion()
            );
        }
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($columnName);
        $this->pdo->exec("ALTER TABLE $qTbl DROP COLUMN $qCol");
    }
}
