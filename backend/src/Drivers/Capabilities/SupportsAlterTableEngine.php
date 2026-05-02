<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsAlterTableEngine
{
    public function alterTableEngine(string $database, string $table, string $engine): void;

    /** @return list<string> */
    public function listTableEngines(): array;
}
