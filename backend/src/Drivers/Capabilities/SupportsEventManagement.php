<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsEventManagement
{
    /** @return list<string> */
    public function listEvents(string $database): array;

    public function getEventDefinition(string $database, string $event): string;

    public function upsertEvent(string $database, string $event, string $definition): void;

    public function dropEvent(string $database, string $event): void;
}
