<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportDropDatabase
{
    public function dropDatabase(string $database): void
    {
        $this->ensureConnected();
        $qDb = $this->quoteIdent($database);
        // Note: DROP DATABASE cannot target the currently connected database.
        $this->pdo->exec("DROP DATABASE $qDb");
    }
}
