<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLite;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLiteDriver */
trait SupportForeignKeys
{
    public function getForeignKeys(string $database, string $table): array
    {
        $this->ensureConnected();
        $qTbl  = $this->quoteIdent($table);
        $stmt  = $this->pdo->query("PRAGMA foreign_key_list($qTbl)");
        $outgoing = [];
        foreach ($stmt->fetchAll() as $r) {
            $outgoing[] = [
                'column'             => $r['from'],
                'referencedDatabase' => 'main',
                'referencedTable'    => $r['table'],
                'referencedColumn'   => $r['to'],
                'constraintName'     => 'fk_' . $r['id'],
                'onUpdate'           => $r['on_update'],
                'onDelete'           => $r['on_delete'],
            ];
        }

        // SQLite PRAGMA doesn't give incoming FKs directly; skip for now.
        return ['outgoing' => $outgoing, 'incoming' => []];
    }
}
