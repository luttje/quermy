<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLite;

/** @mixin \Quermy\Drivers\SQLiteDriver */
trait SupportCreateTable
{
    public function createTable(string $database, string $table, ?string $collation = null, ?string $engine = null): void
    {
        $this->ensureConnected();
        $qTbl = $this->quoteIdent($table);
        $this->pdo->exec("CREATE TABLE $qTbl (\"id\" INTEGER PRIMARY KEY AUTOINCREMENT)");
    }
}
