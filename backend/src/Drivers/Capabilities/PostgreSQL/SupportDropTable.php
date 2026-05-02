<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportDropTable
{
    public function dropTable(string $database, string $table, bool $force = false): void
    {
        $this->ensureConnected();
        $qDb      = $this->quoteIdent($database);
        $qTbl     = $this->quoteIdent($table);
        $cascade  = $force ? ' CASCADE' : '';
        $this->pdo->exec("DROP TABLE $qDb.$qTbl$cascade");
    }
}
