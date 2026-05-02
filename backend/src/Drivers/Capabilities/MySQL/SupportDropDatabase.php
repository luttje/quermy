<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportDropDatabase
{
    public function dropDatabase(string $database): void
    {
        $this->ensureConnected();
        $db = $this->validateIdent($database);
        $this->pdo->exec("DROP DATABASE `$db`");
    }
}
