<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportAddColumn
{
    public function addColumn(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($definition['name'] ?? '');
        $type = $this->sanitizeColumnType($definition['type'] ?? '');

        if (!empty($definition['autoIncrement'])) {
            $this->pdo->exec("ALTER TABLE dbo.$qTbl ADD $qCol $type IDENTITY(1,1) NOT NULL");
            return;
        }

        $null = ($definition['nullable'] ?? true) ? ' NULL' : ' NOT NULL';
        $def  = (isset($definition['default']) && $definition['default'] !== null)
                    ? ' DEFAULT ' . $this->pdo->quote((string)$definition['default'])
                    : '';
        $this->pdo->exec("ALTER TABLE dbo.$qTbl ADD $qCol $type$null$def");
    }
}
