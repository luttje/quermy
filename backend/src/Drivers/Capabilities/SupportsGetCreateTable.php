<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsGetCreateTable
{
    public function getCreateTable(string $database, string $table): string;
}
