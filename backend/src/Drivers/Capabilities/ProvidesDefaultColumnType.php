<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface ProvidesDefaultColumnType
{
    /** The type string pre-filled when a user adds a new column. */
    public function defaultColumnType(): string;
}
