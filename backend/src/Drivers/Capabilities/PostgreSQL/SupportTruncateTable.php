<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportTruncateTable
{
    public function truncateTable(string $database, string $table, bool $force = false): void
    {
        $this->ensureConnected();
        $qDb     = $this->quoteIdent($database);
        $qTbl    = $this->quoteIdent($table);
        $cascade = $force ? ' CASCADE' : '';
        $this->pdo->exec("TRUNCATE $qDb.$qTbl$cascade");
    }
}
