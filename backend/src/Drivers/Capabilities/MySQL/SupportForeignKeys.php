<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportForeignKeys
{
    public function getForeignKeys(string $database, string $table): array
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot get foreign keys for $database");
        }

        $this->ensureConnected();

        // Outgoing: this table → others.
        $outStmt = $this->pdo->prepare(
            "SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME,
                    k.REFERENCED_COLUMN_NAME, k.CONSTRAINT_NAME,
                    r.UPDATE_RULE, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = :db
               AND k.TABLE_NAME   = :tbl
               AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION"
        );
        $outStmt->execute([':db' => $database, ':tbl' => $table]);
        $outgoing = [];
        foreach ($outStmt->fetchAll() as $r) {
            $outgoing[] = [
                'column'             => $r['COLUMN_NAME'],
                'referencedDatabase' => $r['REFERENCED_TABLE_SCHEMA'],
                'referencedTable'    => $r['REFERENCED_TABLE_NAME'],
                'referencedColumn'   => $r['REFERENCED_COLUMN_NAME'],
                'constraintName'     => $r['CONSTRAINT_NAME'],
                'onUpdate'           => $r['UPDATE_RULE'],
                'onDelete'           => $r['DELETE_RULE'],
            ];
        }

        // Incoming: others → this table.
        $inStmt = $this->pdo->prepare(
            "SELECT k.TABLE_SCHEMA  AS REFERENCING_SCHEMA,
                    k.TABLE_NAME    AS REFERENCING_TABLE,
                    k.COLUMN_NAME   AS REFERENCING_COLUMN,
                    k.REFERENCED_COLUMN_NAME,
                    k.CONSTRAINT_NAME,
                    r.UPDATE_RULE, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
             WHERE k.REFERENCED_TABLE_SCHEMA = :db
               AND k.REFERENCED_TABLE_NAME   = :tbl
             ORDER BY k.TABLE_SCHEMA, k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION"
        );
        $inStmt->execute([':db' => $database, ':tbl' => $table]);
        $incoming = [];
        foreach ($inStmt->fetchAll() as $r) {
            $incoming[] = [
                'column'              => $r['REFERENCED_COLUMN_NAME'],
                'referencingDatabase' => $r['REFERENCING_SCHEMA'],
                'referencingTable'    => $r['REFERENCING_TABLE'],
                'referencingColumn'   => $r['REFERENCING_COLUMN'],
                'constraintName'      => $r['CONSTRAINT_NAME'],
                'onUpdate'            => $r['UPDATE_RULE'],
                'onDelete'            => $r['DELETE_RULE'],
            ];
        }

        return [
            'outgoing' => $outgoing,
            'incoming' => $incoming,
        ];
    }
}
