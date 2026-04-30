<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsDropDatabase
{
    public function dropDatabase(string $database): void;
}
