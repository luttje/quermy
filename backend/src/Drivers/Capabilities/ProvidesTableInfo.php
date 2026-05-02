<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface ProvidesTableInfo
{
    /**
     * @return array{name:string, collation:string|null, engine:string|null, autoIncrement:int|null}
     */
    public function getTableInfo(string $database, string $table): array;
}
