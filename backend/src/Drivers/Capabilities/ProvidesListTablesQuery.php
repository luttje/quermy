<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface ProvidesListTablesQuery
{
    public function listTablesQuery(): string;
}
