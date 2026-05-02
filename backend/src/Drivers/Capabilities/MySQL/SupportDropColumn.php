<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportDropColumn
{
    public function dropColumn(string $database, string $table, string $columnName): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot drop column in $database");
        }

        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($columnName);
        $this->pdo->exec("ALTER TABLE $qDb.$qTbl DROP COLUMN $qCol");
    }
}
