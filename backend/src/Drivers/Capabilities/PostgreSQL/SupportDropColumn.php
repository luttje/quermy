<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportDropColumn
{
    public function dropColumn(string $database, string $table, string $columnName): void
    {
        $this->ensureConnected();
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($columnName);
        $this->pdo->exec("ALTER TABLE public.$qTbl DROP COLUMN $qCol");
    }
}
