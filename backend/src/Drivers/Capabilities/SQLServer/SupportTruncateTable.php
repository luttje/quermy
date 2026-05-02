<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportTruncateTable
{
    public function truncateTable(string $database, string $table, bool $force = false): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec('USE ' . $this->quoteIdent($this->validateIdent($database)));
        }
        $qTbl = $this->quoteIdent($table);
        $this->pdo->exec("TRUNCATE TABLE dbo.$qTbl");
    }
}
