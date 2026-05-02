<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportForeignKeyManagement
{
    public function createForeignKey(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        $this->validateIdent($table);
        $this->validateIdent($definition['name']);
        $this->validateIdent($definition['referencedTable']);
        $qTbl    = $this->quoteIdent($table);
        $qCons   = $this->quoteIdent($definition['name']);
        $qRefTbl = $this->quoteIdent($definition['referencedTable']);
        $cols    = implode(', ', array_map([$this, 'quoteIdent'], $definition['columns']));
        $refCols = implode(', ', array_map([$this, 'quoteIdent'], $definition['referencedColumns']));
        $onUpdate = $this->sanitizeReferentialAction($definition['onUpdate'] ?? 'RESTRICT');
        $onDelete = $this->sanitizeReferentialAction($definition['onDelete'] ?? 'RESTRICT');

        $this->pdo->exec(
            "ALTER TABLE public.$qTbl ADD CONSTRAINT $qCons "
            . "FOREIGN KEY ($cols) REFERENCES public.$qRefTbl ($refCols) "
            . "ON UPDATE $onUpdate ON DELETE $onDelete"
        );
    }

    public function dropForeignKey(string $database, string $table, string $constraintName): void
    {
        $this->ensureConnected();
        $this->validateIdent($table);
        $this->validateIdent($constraintName);
        $qTbl  = $this->quoteIdent($table);
        $qCons = $this->quoteIdent($constraintName);
        $this->pdo->exec("ALTER TABLE public.$qTbl DROP CONSTRAINT $qCons");
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
