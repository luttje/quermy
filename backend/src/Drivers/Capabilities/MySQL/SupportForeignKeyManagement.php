<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportForeignKeyManagement
{
    public function createForeignKey(string $database, string $table, array $definition): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot create foreign key in $database");
        }

        $this->ensureConnected();
        $this->validateIdent($definition['name']);
        $this->validateIdent($definition['referencedTable']);
        $qDb     = $this->quoteIdent($database);
        $qTbl    = $this->quoteIdent($table);
        $qCons   = $this->quoteIdent($definition['name']);
        $qRef    = $this->quoteIdent($definition['referencedTable']);
        $cols    = implode(', ', array_map([$this, 'quoteIdent'], $definition['columns']));
        $refCols = implode(', ', array_map([$this, 'quoteIdent'], $definition['referencedColumns']));
        $onUpdate = $this->sanitizeReferentialAction($definition['onUpdate'] ?? 'RESTRICT');
        $onDelete = $this->sanitizeReferentialAction($definition['onDelete'] ?? 'RESTRICT');

        $this->pdo->exec(
            "ALTER TABLE $qDb.$qTbl ADD CONSTRAINT $qCons "
            . "FOREIGN KEY ($cols) REFERENCES $qDb.$qRef ($refCols) "
            . "ON UPDATE $onUpdate ON DELETE $onDelete"
        );
    }

    public function dropForeignKey(string $database, string $table, string $constraintName): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot drop foreign key in $database");
        }

        $this->ensureConnected();
        $this->validateIdent($constraintName);
        $qDb   = $this->quoteIdent($database);
        $qTbl  = $this->quoteIdent($table);
        $qCons = $this->quoteIdent($constraintName);
        $this->pdo->exec("ALTER TABLE $qDb.$qTbl DROP FOREIGN KEY $qCons");
    }

    private function sanitizeReferentialAction(string $action): string
    {
        $valid  = ['CASCADE', 'RESTRICT', 'SET NULL', 'SET DEFAULT', 'NO ACTION'];
        $action = strtoupper(trim($action));
        if (!in_array($action, $valid, true)) {
            throw new RuntimeException("Invalid referential action: $action");
        }
        return $action;
    }
}
