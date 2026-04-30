<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsForeignKeyManagement
{
    /** @param array{name:string,columns:list<string>,referencedTable:string,referencedColumns:list<string>,onUpdate:string,onDelete:string} $definition */
    public function createForeignKey(string $database, string $table, array $definition): void;

    public function dropForeignKey(string $database, string $table, string $constraintName): void;
}
