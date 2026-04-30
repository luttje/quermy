<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsReorderColumn
{
    public function reorderColumn(string $database, string $table, string $columnName, ?string $afterColumn): void;
}
