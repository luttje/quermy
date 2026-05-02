<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportAlterDatabaseCollation
{
    public function alterDatabaseCollation(string $database, string $collation): void
    {
        $this->ensureConnected();
        $db   = $this->validateIdent($database);
        $coll = $this->sanitizeCollationName($collation);

        // Resolve the character set for this collation
        $stmt = $this->pdo->prepare(
            'SELECT CHARACTER_SET_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME = :coll'
        );
        $stmt->execute([':coll' => $coll]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Unknown collation: $coll");
        }
        $charset = $this->validateIdent($row['CHARACTER_SET_NAME']);
        $this->pdo->exec("ALTER DATABASE `$db` CHARACTER SET `$charset` COLLATE `$coll`");
    }

    public function listDatabaseCollations(string $database): array
    {
        $this->ensureConnected();

        $all = $this->pdo->query('SELECT COLLATION_NAME FROM information_schema.COLLATIONS ORDER BY COLLATION_NAME');
        return array_map(static fn($r) => $r['COLLATION_NAME'], $all->fetchAll());
    }
}
