<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportDropTable
{
    public function dropTable(string $database, string $table, bool $force = false): void
    {
        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        if ($force) {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        }
        try {
            $this->pdo->exec("DROP TABLE $qDb.$qTbl");
        } finally {
            if ($force) {
                $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            }
        }
    }
}
