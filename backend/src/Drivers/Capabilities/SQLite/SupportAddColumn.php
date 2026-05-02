<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLite;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLiteDriver */
trait SupportAddColumn
{
    public function addColumn(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($definition['name'] ?? '');
        $type = $this->sanitizeColumnType($definition['type'] ?? '');
        $null = ($definition['nullable'] ?? true) ? '' : ' NOT NULL';
        $def  = empty($definition['autoIncrement'])
                    ? (isset($definition['default']) && $definition['default'] !== null
                        ? ' DEFAULT ' . $this->pdo->quote((string)$definition['default'])
                        : '')
                    : '';
        // SQLite ADD COLUMN does not support AFTER or position hints.
        $this->pdo->exec("ALTER TABLE $qTbl ADD COLUMN $qCol $type$null$def");
    }
}
