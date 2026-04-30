<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsIndexManagement
{
    /** @param array{name:string,columns:list<string>,unique:bool,primary:bool} $definition */
    public function createIndex(string $database, string $table, array $definition): void;

    public function dropIndex(string $database, string $table, string $indexName, bool $isPrimary): void;
}
