<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportForeignKeyManagement
{
    public function createForeignKey(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec('USE ' . $this->quoteIdent($this->validateIdent($database)));
        }
        $this->validateIdent($definition['name']);
        $this->validateIdent($definition['referencedTable']);
        $qDb     = $database !== '' ? $this->quoteIdent($database) . '.' : '';
        $qTbl    = $this->quoteIdent($table);
        $qCons   = $this->quoteIdent($definition['name']);
        $qRefTbl = $this->quoteIdent($definition['referencedTable']);
        $cols    = implode(', ', array_map([$this, 'quoteIdent'], $definition['columns']));
        $refCols = implode(', ', array_map([$this, 'quoteIdent'], $definition['referencedColumns']));
        $onUpdate = $this->sanitizeReferentialAction($definition['onUpdate'] ?? 'NO ACTION');
        $onDelete = $this->sanitizeReferentialAction($definition['onDelete'] ?? 'NO ACTION');

        $this->pdo->exec(
            "ALTER TABLE {$qDb}dbo.$qTbl ADD CONSTRAINT $qCons "
            . "FOREIGN KEY ($cols) REFERENCES {$qDb}dbo.$qRefTbl ($refCols) "
            . "ON UPDATE $onUpdate ON DELETE $onDelete"
        );
    }

    public function dropForeignKey(string $database, string $table, string $constraintName): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec('USE ' . $this->quoteIdent($this->validateIdent($database)));
        }
        $this->validateIdent($constraintName);
        $qDb   = $database !== '' ? $this->quoteIdent($database) . '.' : '';
        $qTbl  = $this->quoteIdent($table);
        $qCons = $this->quoteIdent($constraintName);
        $this->pdo->exec("ALTER TABLE {$qDb}dbo.$qTbl DROP CONSTRAINT $qCons");
    }
}
