<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface ProvidesColumnTypesWithLength
{
    /**
     * Base type names (without parentheses) that accept a length / precision
     * argument, e.g. VARCHAR, CHAR, DECIMAL.
     *
     * @return list<string>
     */
    public function columnTypesWithLength(): array;
}
