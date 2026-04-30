<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsFunctionManagement
{
    /** @return list<string> */
    public function listFunctions(string $database): array;

    public function getFunctionDefinition(string $database, string $function): string;

    public function upsertFunction(string $database, string $function, string $definition): void;

    public function dropFunction(string $database, string $function): void;
}
