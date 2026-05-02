<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportAlterTableCollation
{
    public function listTableCollations(string $database, string $table): array
    {
        $this->ensureConnected();
        $all = $this->pdo->query('SELECT COLLATION_NAME FROM information_schema.COLLATIONS ORDER BY COLLATION_NAME');
        return array_map(static fn($r) => $r['COLLATION_NAME'], $all->fetchAll());
    }

    public function alterTableCollation(string $database, string $table, string $collation): void
    {
        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        $coll = $this->sanitizeCollationName($collation);

        $stmt = $this->pdo->prepare(
            'SELECT CHARACTER_SET_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME = :coll'
        );
        $stmt->execute([':coll' => $coll]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Unknown collation: $coll");
        }
        $charset = $row['CHARACTER_SET_NAME'];

        $this->pdo->exec("ALTER TABLE $qDb.$qTbl CONVERT TO CHARACTER SET `$charset` COLLATE `$coll`");
    }
}
