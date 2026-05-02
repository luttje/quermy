<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportAlterTableAutoIncrement
{
    public function alterTableAutoIncrement(string $database, string $table, int $value): void
    {
        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        if ($value < 1) {
            throw new RuntimeException('AUTO_INCREMENT value must be >= 1');
        }
        $this->pdo->exec("ALTER TABLE $qDb.$qTbl AUTO_INCREMENT = $value");
    }
}
