<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportDropTable
{
    public function dropTable(string $database, string $table, bool $force = false): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec('USE ' . $this->quoteIdent($this->validateIdent($database)));
        }
        $qTbl = $this->quoteIdent($table);
        $this->pdo->exec("DROP TABLE dbo.$qTbl");
    }
}
