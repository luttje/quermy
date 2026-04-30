<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsViewManagement
{
    /** @return list<string> */
    public function listViews(string $database): array;

    public function getViewDefinition(string $database, string $view): string;

    public function upsertView(string $database, string $view, string $definition): void;

    public function dropView(string $database, string $view): void;
}
