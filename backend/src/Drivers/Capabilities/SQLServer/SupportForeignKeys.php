<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportForeignKeys
{
    public function getForeignKeys(string $database, string $table): array
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }

        $outStmt = $this->pdo->prepare(
            "SELECT fk.name AS constraint_name,
                    COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS column_name,
                    OBJECT_SCHEMA_NAME(fkc.referenced_object_id) AS ref_schema,
                    OBJECT_NAME(fkc.referenced_object_id) AS ref_table,
                    COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS ref_column,
                    fk.update_referential_action_desc AS on_update,
                    fk.delete_referential_action_desc AS on_delete
             FROM sys.foreign_keys fk
             JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
             WHERE OBJECT_NAME(fk.parent_object_id) = :tbl
             ORDER BY fk.name, fkc.constraint_column_id"
        );
        $outStmt->execute([':tbl' => $table]);
        $outgoing = [];
        foreach ($outStmt->fetchAll() as $r) {
            $outgoing[] = [
                'column'             => $r['column_name'],
                'referencedDatabase' => $database,
                'referencedTable'    => $r['ref_table'],
                'referencedColumn'   => $r['ref_column'],
                'constraintName'     => $r['constraint_name'],
                'onUpdate'           => str_replace('_', ' ', $r['on_update']),
                'onDelete'           => str_replace('_', ' ', $r['on_delete']),
            ];
        }

        $inStmt = $this->pdo->prepare(
            "SELECT fk.name AS constraint_name,
                    OBJECT_NAME(fk.parent_object_id) AS referencing_table,
                    COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS referencing_column,
                    COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS ref_column,
                    fk.update_referential_action_desc AS on_update,
                    fk.delete_referential_action_desc AS on_delete
             FROM sys.foreign_keys fk
             JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
             WHERE OBJECT_NAME(fk.referenced_object_id) = :tbl
             ORDER BY fk.name, fkc.constraint_column_id"
        );
        $inStmt->execute([':tbl' => $table]);
        $incoming = [];
        foreach ($inStmt->fetchAll() as $r) {
            $incoming[] = [
                'column'              => $r['ref_column'],
                'referencingDatabase' => $database,
                'referencingTable'    => $r['referencing_table'],
                'referencingColumn'   => $r['referencing_column'],
                'constraintName'      => $r['constraint_name'],
                'onUpdate'            => str_replace('_', ' ', $r['on_update']),
                'onDelete'            => str_replace('_', ' ', $r['on_delete']),
            ];
        }

        return ['outgoing' => $outgoing, 'incoming' => $incoming];
    }
}
