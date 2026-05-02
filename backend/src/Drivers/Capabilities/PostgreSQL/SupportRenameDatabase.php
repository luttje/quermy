<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportRenameDatabase
{
    public function renameDatabase(string $database, string $newName): void
    {
        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qNew = $this->quoteIdent($newName);
        // Note: ALTER DATABASE RENAME TO cannot rename the currently connected database.
        // The calling user must be connected to a different database for this to succeed.
        $this->pdo->exec("ALTER DATABASE $qDb RENAME TO $qNew");
    }
}
