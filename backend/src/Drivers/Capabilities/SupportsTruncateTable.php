<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsTruncateTable
{
    public function truncateTable(string $database, string $table, bool $force = false): void;
}
