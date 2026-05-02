<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportTriggerManagement
{
    public function listTriggers(string $database, string $table): array
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot list triggers for $database");
        }

        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT TRIGGER_NAME
             FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = :db AND EVENT_OBJECT_TABLE = :table
             ORDER BY TRIGGER_NAME"
        );
        $stmt->execute([':db' => $database, ':table' => $table]);
        return array_map(static fn($r) => $r['TRIGGER_NAME'], $stmt->fetchAll());
    }

    public function getTriggerDefinition(string $database, string $trigger): string
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot read trigger in $database");
        }

        $this->ensureConnected();
        $qDb      = $this->quoteIdent($this->validateIdent($database));
        $qTrigger = $this->quoteIdent($trigger);
        $stmt     = $this->pdo->query("SHOW CREATE TRIGGER $qDb.$qTrigger");
        $row      = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Trigger not found: $database.$trigger");
        }
        return trim((string)($row['SQL Original Statement'] ?? ''));
    }

    public function upsertTrigger(string $database, string $table, string $trigger, string $definition): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot save trigger in $database");
        }

        $this->ensureConnected();
        $name = $this->validateIdent($trigger);
        $body = trim($definition);
        if ($body === '') {
            throw new RuntimeException('Trigger definition is empty');
        }
        if (!preg_match('/^\s*CREATE\b/i', $body)) {
            throw new RuntimeException('Trigger definition must start with CREATE TRIGGER.');
        }

        $qDb      = $this->quoteIdent($database);
        $qTrigger = $this->quoteIdent($name);
        $this->pdo->exec("USE $qDb");
        $this->pdo->exec("DROP TRIGGER IF EXISTS $qDb.$qTrigger");
        $this->pdo->exec($body);
    }

    public function dropTrigger(string $database, string $trigger): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot drop trigger in $database");
        }

        $this->ensureConnected();
        $name     = $this->validateIdent($trigger);
        $qDb      = $this->quoteIdent($database);
        $qTrigger = $this->quoteIdent($name);
        $this->pdo->exec("DROP TRIGGER IF EXISTS $qDb.$qTrigger");
    }
}
