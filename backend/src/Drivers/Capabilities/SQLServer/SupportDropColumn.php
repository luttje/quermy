<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportDropColumn
{
    public function dropColumn(string $database, string $table, string $columnName): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($columnName);
        $this->pdo->exec("ALTER TABLE dbo.$qTbl DROP COLUMN $qCol");
    }
}
