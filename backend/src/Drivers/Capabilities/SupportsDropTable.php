<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsDropTable
{
    public function dropTable(string $database, string $table, bool $force = false): void;
}
