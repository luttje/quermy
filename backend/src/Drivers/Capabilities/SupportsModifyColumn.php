<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsModifyColumn
{
    /** @param array{name:string,type:string,nullable:bool,default:mixed} $definition */
    public function modifyColumn(string $database, string $table, string $columnName, array $definition): void;
}
