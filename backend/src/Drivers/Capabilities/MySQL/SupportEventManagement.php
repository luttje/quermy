<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportEventManagement
{
    public function listEvents(string $database): array
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot list events for $database");
        }

        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT EVENT_NAME
             FROM information_schema.EVENTS
             WHERE EVENT_SCHEMA = :db
             ORDER BY EVENT_NAME"
        );
        $stmt->execute([':db' => $database]);
        return array_map(static fn($r) => $r['EVENT_NAME'], $stmt->fetchAll());
    }

    public function getEventDefinition(string $database, string $event): string
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot read event in $database");
        }

        $this->ensureConnected();
        $qDb    = $this->quoteIdent($this->validateIdent($database));
        $qEvent = $this->quoteIdent($event);
        $stmt   = $this->pdo->query("SHOW CREATE EVENT $qDb.$qEvent");
        $row    = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Event not found: $database.$event");
        }
        return trim((string)($row['Create Event'] ?? ''));
    }

    public function upsertEvent(string $database, string $event, string $definition): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot save event in $database");
        }

        $this->ensureConnected();
        $name = $this->validateIdent($event);
        $body = trim($definition);
        if ($body === '') {
            throw new RuntimeException('Event definition is empty');
        }
        if (!preg_match('/^\s*CREATE\b/i', $body)) {
            throw new RuntimeException('Event definition must start with CREATE EVENT.');
        }

        $qDb    = $this->quoteIdent($database);
        $qEvent = $this->quoteIdent($name);
        // MySQL resolves unqualified identifiers in the view body against the
        // active catalog. Set it explicitly so CREATE VIEW works even when the
        // connection itself was opened without dbname.
        $this->pdo->exec("USE $qDb");
        $this->pdo->exec("DROP EVENT IF EXISTS $qDb.$qEvent");
        $this->pdo->exec($body);
    }

    public function dropEvent(string $database, string $event): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot drop event in $database");
        }

        $this->ensureConnected();
        $name   = $this->validateIdent($event);
        $qDb    = $this->quoteIdent($database);
        $qEvent = $this->quoteIdent($name);
        $this->pdo->exec("DROP EVENT IF EXISTS $qDb.$qEvent");
    }
}
