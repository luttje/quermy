<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportAlterTableEngine
{
    public function listTableEngines(): array
    {
        $this->ensureConnected();
        $stmt = $this->pdo->query('SHOW ENGINES');
        $engines = [];
        foreach ($stmt->fetchAll() as $row) {
            if (in_array(strtoupper((string)$row['Support']), ['YES', 'DEFAULT'], true)) {
                $engines[] = $row['Engine'];
            }
        }
        sort($engines);
        return $engines;
    }

    public function alterTableEngine(string $database, string $table, string $engine): void
    {
        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        if (!preg_match('/^[A-Za-z0-9_]+$/', $engine)) {
            throw new RuntimeException("Invalid engine name: $engine");
        }
        $this->pdo->exec("ALTER TABLE $qDb.$qTbl ENGINE = $engine");
    }
}
