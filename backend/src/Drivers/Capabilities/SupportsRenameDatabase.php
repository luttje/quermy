<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities;

interface SupportsRenameDatabase
{
    public function renameDatabase(string $database, string $newName): void;
}
