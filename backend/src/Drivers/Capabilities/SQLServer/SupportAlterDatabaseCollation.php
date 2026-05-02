<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportAlterDatabaseCollation
{
    public function listDatabaseCollations(string $database): array
    {
        $this->ensureConnected();
        // All supported collations available on the server
        $stmt = $this->pdo->query('SELECT name FROM sys.fn_helpcollations() ORDER BY name');
        return array_map(static fn($r) => $r['name'], $stmt->fetchAll());
    }

    public function alterDatabaseCollation(string $database, string $collation): void
    {
        $this->ensureConnected();
        $this->validateIdent($database);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $collation)) {
            throw new RuntimeException("Invalid collation name: $collation");
        }
        $this->pdo->exec("ALTER DATABASE [$database] COLLATE $collation");
    }
}
