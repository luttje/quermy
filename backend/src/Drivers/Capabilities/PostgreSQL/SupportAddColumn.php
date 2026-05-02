<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportAddColumn
{
    public function addColumn(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($definition['name'] ?? '');
        $type = $this->sanitizeColumnType($definition['type'] ?? '');

        // For autoIncrement use GENERATED ALWAYS AS IDENTITY (PostgreSQL 10+)
        if (!empty($definition['autoIncrement'])) {
            $this->pdo->exec("ALTER TABLE public.$qTbl ADD COLUMN $qCol $type GENERATED ALWAYS AS IDENTITY");
            return;
        }

        $null = ($definition['nullable'] ?? true) ? '' : ' NOT NULL';
        $def  = (isset($definition['default']) && $definition['default'] !== null)
                    ? ' DEFAULT ' . $this->pdo->quote((string)$definition['default'])
                    : (($definition['nullable'] ?? true) ? ' DEFAULT NULL' : '');

        $this->pdo->exec("ALTER TABLE public.$qTbl ADD COLUMN $qCol $type$null$def");
    }
}
