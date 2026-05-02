<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportReorderColumn
{
    public function reorderColumn(string $database, string $table, string $columnName, ?string $afterColumn): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot reorder column in $database");
        }

        $this->ensureConnected();

        // Fetch the current column definition so we can re-issue it with a position clause.
        $stmt = $this->pdo->prepare(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col"
        );
        $stmt->execute([':db' => $database, ':tbl' => $table, ':col' => $columnName]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new RuntimeException("Column not found: $columnName");
        }

        $qDb    = $this->quoteIdent($database);
        $qTbl   = $this->quoteIdent($table);
        $qCol   = $this->quoteIdent($columnName);
        $type   = $this->sanitizeColumnType($row['COLUMN_TYPE']);
        $null   = $row['IS_NULLABLE'] === 'YES' ? '' : ' NOT NULL';
        $extra  = strtolower($row['EXTRA'] ?? '');
        $ai     = str_contains($extra, 'auto_increment') ? ' AUTO_INCREMENT' : '';
        $def    = ($ai === '')
                    ? (isset($row['COLUMN_DEFAULT']) && $row['COLUMN_DEFAULT'] !== null
                        ? ' DEFAULT ' . $this->pdo->quote((string)$row['COLUMN_DEFAULT'])
                        : ($row['IS_NULLABLE'] === 'YES' ? ' DEFAULT NULL' : ''))
                    : '';
        $position = $afterColumn !== null
                        ? ' AFTER ' . $this->quoteIdent($afterColumn)
                        : ' FIRST';

        $this->pdo->exec("ALTER TABLE $qDb.$qTbl MODIFY COLUMN $qCol $type$null$def$ai$position");
    }
}
