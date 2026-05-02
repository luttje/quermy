<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLite;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLiteDriver */
trait SupportIndexManagement
{
    public function createIndex(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        if (!empty($definition['primary'])) {
            throw new RuntimeException(
                'SQLite does not support adding a primary key after table creation. '
                . 'To change a primary key, recreate the table.'
            );
        }
        $this->validateIdent($definition['name']);
        $this->validateIdent($table);
        $qTbl = $this->quoteIdent($table);
        $qIdx = $this->quoteIdent($definition['name']);
        $cols = implode(', ', array_map([$this, 'quoteIdent'], $definition['columns']));
        $uniq = !empty($definition['unique']) ? 'UNIQUE ' : '';
        $this->pdo->exec("CREATE {$uniq}INDEX $qIdx ON $qTbl ($cols)");
    }

    public function dropIndex(string $database, string $table, string $indexName, bool $isPrimary): void
    {
        $this->ensureConnected();
        if ($isPrimary) {
            throw new RuntimeException(
                'SQLite does not support dropping a primary key without recreating the table.'
            );
        }
        $this->validateIdent($indexName);
        $qIdx = $this->quoteIdent($indexName);
        $this->pdo->exec("DROP INDEX $qIdx");
    }
}
