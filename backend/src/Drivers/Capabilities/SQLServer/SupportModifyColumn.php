<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportModifyColumn
{
    public function modifyColumn(string $database, string $table, string $columnName, array $definition): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $qTbl  = $this->quoteIdent($table);
        $qOld  = $this->quoteIdent($columnName);
        $qNew  = $this->quoteIdent($definition['name'] ?? $columnName);
        $type  = $this->sanitizeColumnType($definition['type'] ?? '');
        $null  = ($definition['nullable'] ?? true) ? ' NULL' : ' NOT NULL';
        $def   = (isset($definition['default']) && $definition['default'] !== null)
                     ? ' DEFAULT ' . $this->pdo->quote((string)$definition['default'])
                     : '';

        // Retype first, then rename if needed (SQL Server doesn't combine them).
        $this->pdo->exec("ALTER TABLE dbo.$qTbl ALTER COLUMN $qOld $type$null$def");

        if ($qOld !== $qNew) {
            // sp_rename is the SQL Server way to rename columns.
            $spTable  = $this->pdo->quote("dbo.$table.$columnName");
            $spNewCol = $this->pdo->quote($definition['name']);
            $this->pdo->exec("EXEC sp_rename $spTable, $spNewCol, 'COLUMN'");
        }
    }
}
