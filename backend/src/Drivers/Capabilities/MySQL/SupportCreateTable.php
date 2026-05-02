<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportCreateTable
{
    public function createTable(string $database, string $table, ?string $collation = null, ?string $engine = null): void
    {
        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        $sql  = "CREATE TABLE $qDb.$qTbl (`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY)";
        if ($engine !== null && $engine !== '') {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $engine)) {
                throw new RuntimeException("Invalid engine name: $engine");
            }
            $sql .= " ENGINE=$engine";
        }
        if ($collation !== null && $collation !== '') {
            if (!preg_match('/^[A-Za-z0-9_]+$/', $collation)) {
                throw new RuntimeException("Invalid collation name: $collation");
            }
            $sql .= " COLLATE=$collation";
        }
        $this->pdo->exec($sql);
    }
}
