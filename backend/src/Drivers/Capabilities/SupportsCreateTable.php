<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsCreateTable
{
    public function createTable(string $database, string $table, ?string $collation = null, ?string $engine = null): void;
}
