<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportTruncateTable
{
    public function truncateTable(string $database, string $table, bool $force = false): void
    {
        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        if ($force) {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        }
        try {
            $this->pdo->exec("TRUNCATE TABLE $qDb.$qTbl");
        } finally {
            if ($force) {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        }
    }
}
