<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface ProvidesStructureQueryTemplate
{
    public function structureQueryTemplate(): string;
}
