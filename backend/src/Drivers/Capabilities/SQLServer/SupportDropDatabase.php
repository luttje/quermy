<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportDropDatabase
{
    public function dropDatabase(string $database): void
    {
        $this->ensureConnected();
        $this->validateIdent($database);
        $this->pdo->exec("DROP DATABASE [$database]");
    }
}
