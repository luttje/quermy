<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsAlterTableAutoIncrement
{
    public function alterTableAutoIncrement(string $database, string $table, int $value): void;
}
