<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLite;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLiteDriver */
trait SupportGetCreateTable
{
    public function getCreateTable(string $database, string $table): string
    {
        $this->ensureConnected();
        $stmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :tbl");
        $stmt->execute([':tbl' => $table]);
        $row = $stmt->fetch();
        if (!$row || !$row['sql']) {
            throw new RuntimeException("Table not found: $table");
        }
        return $row['sql'];
    }
}
