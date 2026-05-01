<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface ProvidesReferentialActions
{
    /** @return list<string> Referential actions supported by this engine (e.g. RESTRICT, CASCADE). */
    public function referentialActions(): array;
}
