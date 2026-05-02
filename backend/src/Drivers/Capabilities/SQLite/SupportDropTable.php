<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLite;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLiteDriver */
trait SupportDropTable
{
    public function dropTable(string $database, string $table, bool $force = false): void
    {
        $this->ensureConnected();
        $qTbl = $this->quoteIdent($table);
        $this->pdo->exec("DROP TABLE $qTbl");
    }
}
