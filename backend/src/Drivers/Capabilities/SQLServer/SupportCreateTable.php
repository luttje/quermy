<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportCreateTable
{
    public function createTable(string $database, string $table, ?string $collation = null, ?string $engine = null): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec('USE ' . $this->quoteIdent($this->validateIdent($database)));
        }
        $qTbl = $this->quoteIdent($table);
        $this->pdo->exec("CREATE TABLE dbo.$qTbl ([id] INT NOT NULL IDENTITY(1,1) CONSTRAINT [PK_{$table}] PRIMARY KEY)");
    }
}
