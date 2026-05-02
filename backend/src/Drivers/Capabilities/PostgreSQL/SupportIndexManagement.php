<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportIndexManagement
{
    public function createIndex(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        $this->validateIdent($table);
        $qTbl = $this->quoteIdent($table);
        $cols = implode(', ', array_map([$this, 'quoteIdent'], $definition['columns']));

        if (!empty($definition['primary'])) {
            $this->pdo->exec("ALTER TABLE public.$qTbl ADD PRIMARY KEY ($cols)");
        } else {
            $this->validateIdent($definition['name']);
            $qIdx = $this->quoteIdent($definition['name']);
            $uniq = !empty($definition['unique']) ? 'UNIQUE ' : '';
            $this->pdo->exec("CREATE {$uniq}INDEX $qIdx ON public.$qTbl ($cols)");
        }
    }

    public function dropIndex(string $database, string $table, string $indexName, bool $isPrimary): void
    {
        $this->ensureConnected();
        $this->validateIdent($table);
        $qTbl = $this->quoteIdent($table);

        if ($isPrimary) {
            // Use the provided index name — it IS the constraint name in PostgreSQL (e.g. table_pkey).
            $this->validateIdent($indexName);
            $qCons = $this->quoteIdent($indexName);
            $this->pdo->exec("ALTER TABLE public.$qTbl DROP CONSTRAINT $qCons");
        } else {
            $this->validateIdent($indexName);
            $qIdx = $this->quoteIdent($indexName);
            $this->pdo->exec("DROP INDEX public.$qIdx");
        }
    }
}
