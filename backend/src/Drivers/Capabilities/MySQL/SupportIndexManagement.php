<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportIndexManagement
{
    public function createIndex(string $database, string $table, array $definition): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot create index in $database");
        }

        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        $cols = implode(', ', array_map([$this, 'quoteIdent'], $definition['columns']));

        if (!empty($definition['primary'])) {
            $this->pdo->exec("ALTER TABLE $qDb.$qTbl ADD PRIMARY KEY ($cols)");
        } else {
            $this->validateIdent($definition['name']);
            $qIdx = $this->quoteIdent($definition['name']);
            $uniq = !empty($definition['unique']) ? 'UNIQUE ' : '';
            $this->pdo->exec("CREATE {$uniq}INDEX $qIdx ON $qDb.$qTbl ($cols)");
        }
    }

    public function dropIndex(string $database, string $table, string $indexName, bool $isPrimary): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot drop index in $database");
        }

        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);

        if ($isPrimary) {
            $this->pdo->exec("ALTER TABLE $qDb.$qTbl DROP PRIMARY KEY");
        } else {
            $this->validateIdent($indexName);
            $qIdx = $this->quoteIdent($indexName);
            $this->pdo->exec("DROP INDEX $qIdx ON $qDb.$qTbl");
        }
    }
}
