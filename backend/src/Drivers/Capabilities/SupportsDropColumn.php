<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsDropColumn
{
    public function dropColumn(string $database, string $table, string $columnName): void;
}
