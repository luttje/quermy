<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportExplain
{
    public function explainQuery(string $database, string $sql): array
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot explain query for $database");
        }

        $this->ensureConnected();

        // Defense in depth — the tool already checks this, but the driver
        // is the last line of defense and shouldn't trust its caller.
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            throw new RuntimeException('explainQuery only accepts SELECT statements.');
        }
        // Reject multi-statement payloads. We can't fully parse SQL with a
        // regex but we can refuse the obvious case where someone tries to
        // chain a destructive statement after the SELECT.
        if (preg_match('/;\s*\S/', rtrim($sql, "; \t\n\r"))) {
            throw new RuntimeException('explainQuery accepts only a single statement.');
        }

        if ($database !== '') {
            $database = $this->validateIdent($database);
            $this->pdo->exec("USE `$database`");
        }

        // Plain (tabular) EXPLAIN is enough for the agent — FORMAT=JSON is
        // richer but bulkier and harder for the LLM to read.
        $stmt = $this->pdo->query('EXPLAIN ' . $sql);
        return $stmt->fetchAll();
    }
}
