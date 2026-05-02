<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLite;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLiteDriver */
trait SupportTruncateTable
{
    public function truncateTable(string $database, string $table, bool $force = false): void
    {
        $this->ensureConnected();
        $qTbl = $this->quoteIdent($table);
        // SQLite has no TRUNCATE; DELETE FROM is equivalent and also resets the rowid sequence.
        $this->pdo->exec("DELETE FROM $qTbl");
    }
}
