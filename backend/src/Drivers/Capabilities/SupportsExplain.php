<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsExplain
{
    /** @return list<array<string,mixed>> */
    public function explainQuery(string $database, string $sql): array;
}
