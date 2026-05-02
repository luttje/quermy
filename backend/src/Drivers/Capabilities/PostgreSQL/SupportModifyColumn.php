<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportModifyColumn
{
    public function modifyColumn(string $database, string $table, string $columnName, array $definition): void
    {
        $this->ensureConnected();
        $qTbl  = $this->quoteIdent($table);
        $qOld  = $this->quoteIdent($columnName);
        $qNew  = $this->quoteIdent($definition['name'] ?? $columnName);
        $type  = $this->sanitizeColumnType($definition['type'] ?? '');
        $null  = ($definition['nullable'] ?? true);

        // PostgreSQL requires separate ALTER COLUMN statements for each attribute.
        $this->pdo->exec("ALTER TABLE public.$qTbl ALTER COLUMN $qOld TYPE $type USING $qOld::$type");

        if ($null) {
            $this->pdo->exec("ALTER TABLE public.$qTbl ALTER COLUMN $qOld DROP NOT NULL");
        } else {
            $this->pdo->exec("ALTER TABLE public.$qTbl ALTER COLUMN $qOld SET NOT NULL");
        }

        if (isset($definition['default']) && $definition['default'] !== null) {
            $this->pdo->exec("ALTER TABLE public.$qTbl ALTER COLUMN $qOld SET DEFAULT " . $this->pdo->quote((string)$definition['default']));
        } else {
            $this->pdo->exec("ALTER TABLE public.$qTbl ALTER COLUMN $qOld DROP DEFAULT");
        }

        // Rename last to avoid ambiguity in the statements above.
        if ($qOld !== $qNew) {
            $this->pdo->exec("ALTER TABLE public.$qTbl RENAME COLUMN $qOld TO $qNew");
        }
    }
}
