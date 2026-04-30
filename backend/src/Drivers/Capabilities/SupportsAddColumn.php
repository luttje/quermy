<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsAddColumn
{
    /** @param array{name:string,type:string,nullable:bool,default:mixed,after?:string} $definition */
    public function addColumn(string $database, string $table, array $definition): void;
}
