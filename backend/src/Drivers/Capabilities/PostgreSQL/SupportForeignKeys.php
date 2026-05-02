<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportForeignKeys
{
    public function getForeignKeys(string $database, string $table): array
    {
        $this->ensureConnected();

        $outStmt = $this->pdo->prepare(
            "SELECT kcu.column_name, ccu.table_schema AS referenced_schema,
                    ccu.table_name AS referenced_table, ccu.column_name AS referenced_column,
                    tc.constraint_name, rc.update_rule, rc.delete_rule
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
             JOIN information_schema.referential_constraints rc
               ON rc.constraint_name = tc.constraint_name AND rc.constraint_schema = tc.table_schema
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
             WHERE tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_schema = 'public' AND tc.table_name = :tbl
             ORDER BY tc.constraint_name, kcu.ordinal_position"
        );
        $outStmt->execute([':tbl' => $table]);
        $outgoing = [];
        foreach ($outStmt->fetchAll() as $r) {
            $outgoing[] = [
                'column'             => $r['column_name'],
                'referencedDatabase' => $r['referenced_schema'],
                'referencedTable'    => $r['referenced_table'],
                'referencedColumn'   => $r['referenced_column'],
                'constraintName'     => $r['constraint_name'],
                'onUpdate'           => $r['update_rule'],
                'onDelete'           => $r['delete_rule'],
            ];
        }

        $inStmt = $this->pdo->prepare(
            "SELECT kcu.table_name AS referencing_table, kcu.column_name AS referencing_column,
                    ccu.column_name AS referenced_column, tc.constraint_name,
                    rc.update_rule, rc.delete_rule
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
             JOIN information_schema.referential_constraints rc
               ON rc.constraint_name = tc.constraint_name AND rc.constraint_schema = tc.table_schema
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
             WHERE tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_schema = 'public' AND ccu.table_name = :tbl
             ORDER BY kcu.table_name, tc.constraint_name"
        );
        $inStmt->execute([':tbl' => $table]);
        $incoming = [];
        foreach ($inStmt->fetchAll() as $r) {
            $incoming[] = [
                'column'              => $r['referenced_column'],
                'referencingDatabase' => 'public',
                'referencingTable'    => $r['referencing_table'],
                'referencingColumn'   => $r['referencing_column'],
                'constraintName'      => $r['constraint_name'],
                'onUpdate'            => $r['update_rule'],
                'onDelete'            => $r['delete_rule'],
            ];
        }

        return ['outgoing' => $outgoing, 'incoming' => $incoming];
    }
}
