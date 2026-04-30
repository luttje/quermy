<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsProcedureManagement
{
    /** @return list<string> */
    public function listProcedures(string $database): array;

    public function getProcedureDefinition(string $database, string $procedure): string;

    public function upsertProcedure(string $database, string $procedure, string $definition): void;

    public function dropProcedure(string $database, string $procedure): void;
}
