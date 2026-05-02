<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportCreateTable
{
    public function createTable(string $database, string $table, ?string $collation = null, ?string $engine = null): void
    {
        $this->ensureConnected();
        $qTbl = $this->quoteIdent($table);
        $this->pdo->exec("CREATE TABLE public.$qTbl (\"id\" SERIAL PRIMARY KEY)");
    }
}
