<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsAlterDatabaseCollation
{
    public function alterDatabaseCollation(string $database, string $collation): void;

    /** @return list<string> */
    public function listDatabaseCollations(string $database): array;
}
