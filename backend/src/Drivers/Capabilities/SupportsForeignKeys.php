<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsForeignKeys
{
    /**
     * @return array{
     *   outgoing: list<array{column:string,referencedDatabase:string,referencedTable:string,referencedColumn:string,constraintName:string,onUpdate:string,onDelete:string}>,
     *   incoming: list<array{column:string,referencingDatabase:string,referencingTable:string,referencingColumn:string,constraintName:string,onUpdate:string,onDelete:string}>
     * }
     */
    public function getForeignKeys(string $database, string $table): array;
}
