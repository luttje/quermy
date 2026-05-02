<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsTriggerManagement
{
    /** @return list<string> */
    public function listTriggers(string $database, string $table): array;

    public function getTriggerDefinition(string $database, string $trigger): string;

    public function upsertTrigger(string $database, string $table, string $trigger, string $definition): void;

    public function dropTrigger(string $database, string $trigger): void;
}
