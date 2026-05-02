<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportRenameDatabase
{
    public function renameDatabase(string $database, string $newName): void
    {
        $this->ensureConnected();
        $this->validateIdent($database);
        $this->validateIdent($newName);
        $this->pdo->exec("ALTER DATABASE [$database] MODIFY NAME = [$newName]");
    }
}
