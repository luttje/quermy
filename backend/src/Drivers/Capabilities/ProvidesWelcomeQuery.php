<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface ProvidesWelcomeQuery
{
    public function welcomeQuery(): string;
}
