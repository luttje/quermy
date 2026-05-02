<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsAlterTableCollation
{
    public function alterTableCollation(string $database, string $table, string $collation): void;

    /** @return list<string> */
    public function listTableCollations(string $database, string $table): array;
}
