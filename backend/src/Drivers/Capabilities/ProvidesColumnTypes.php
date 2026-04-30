<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface ProvidesColumnTypes
{
    /** @return list<string> */
    public function columnTypes(): array;
}
