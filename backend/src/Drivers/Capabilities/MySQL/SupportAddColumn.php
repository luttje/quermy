<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportAddColumn
{
    public function addColumn(string $database, string $table, array $definition): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot add column in $database");
        }

        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($definition['name'] ?? '');
        $type = $this->sanitizeColumnType($definition['type'] ?? '');
        $null = ($definition['nullable'] ?? true) ? '' : ' NOT NULL';
        $ai   = !empty($definition['autoIncrement']) ? ' AUTO_INCREMENT' : '';
        // AUTO_INCREMENT columns must not have a DEFAULT clause
        $def  = empty($definition['autoIncrement'])
                    ? (isset($definition['default']) && $definition['default'] !== null
                        ? ' DEFAULT ' . $this->pdo->quote((string)$definition['default'])
                        : (($definition['nullable'] ?? true) ? ' DEFAULT NULL' : ''))
                    : '';
        $after = !empty($definition['after'])
                    ? ' AFTER ' . $this->quoteIdent($definition['after'])
                    : '';
        $this->pdo->exec("ALTER TABLE $qDb.$qTbl ADD COLUMN $qCol $type$null$def$ai$after");
    }
}
