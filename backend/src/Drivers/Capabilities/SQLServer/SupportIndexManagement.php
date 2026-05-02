<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportIndexManagement
{
    public function createIndex(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec('USE ' . $this->quoteIdent($this->validateIdent($database)));
        }
        $qDb  = $database !== '' ? $this->quoteIdent($database) . '.' : '';
        $qTbl = $this->quoteIdent($table);
        $cols = implode(', ', array_map([$this, 'quoteIdent'], $definition['columns']));

        if (!empty($definition['primary'])) {
            $this->pdo->exec("ALTER TABLE {$qDb}dbo.$qTbl ADD PRIMARY KEY ($cols)");
        } else {
            $this->validateIdent($definition['name']);
            $qIdx = $this->quoteIdent($definition['name']);
            $uniq = !empty($definition['unique']) ? 'UNIQUE ' : '';
            $this->pdo->exec("CREATE {$uniq}INDEX $qIdx ON {$qDb}dbo.$qTbl ($cols)");
        }
    }

    public function dropIndex(string $database, string $table, string $indexName, bool $isPrimary): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec('USE ' . $this->quoteIdent($this->validateIdent($database)));
        }
        $qDb  = $database !== '' ? $this->quoteIdent($database) . '.' : '';
        $qTbl = $this->quoteIdent($table);

        if ($isPrimary) {
            // The PK constraint name IS the index name in SQL Server's sys.indexes.
            $this->validateIdent($indexName);
            $qCons = $this->quoteIdent($indexName);
            $this->pdo->exec("ALTER TABLE {$qDb}dbo.$qTbl DROP CONSTRAINT $qCons");
        } else {
            $this->validateIdent($indexName);
            $qIdx = $this->quoteIdent($indexName);
            $this->pdo->exec("DROP INDEX $qIdx ON {$qDb}dbo.$qTbl");
        }
    }
}
