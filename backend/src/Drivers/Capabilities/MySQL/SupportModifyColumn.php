<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportModifyColumn
{
    public function modifyColumn(string $database, string $table, string $columnName, array $definition): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot modify column in $database");
        }

        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        $qOld = $this->quoteIdent($columnName);
        $qNew = $this->quoteIdent($definition['name'] ?? $columnName);
        $type = $this->sanitizeColumnType($definition['type'] ?? '');
        $null = ($definition['nullable'] ?? true) ? '' : ' NOT NULL';
        // AUTO_INCREMENT columns must not have a DEFAULT clause
        $ai  = !empty($definition['autoIncrement']) ? ' AUTO_INCREMENT' : '';
        $def  = empty($definition['autoIncrement'])
                    ? (isset($definition['default']) && $definition['default'] !== null
                        ? ' DEFAULT ' . $this->pdo->quote((string)$definition['default'])
                        : (($definition['nullable'] ?? true) ? ' DEFAULT NULL' : ''))
                    : '';
        $this->pdo->exec("ALTER TABLE $qDb.$qTbl CHANGE COLUMN $qOld $qNew $type$null$def$ai");
    }
}
