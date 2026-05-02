<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportViewManagement
{
    public function listViews(string $database): array
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot list views for $database");
        }

        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT TABLE_NAME
             FROM information_schema.VIEWS
             WHERE TABLE_SCHEMA = :db
             ORDER BY TABLE_NAME"
        );
        $stmt->execute([':db' => $database]);
        return array_map(static fn($r) => $r['TABLE_NAME'], $stmt->fetchAll());
    }

    public function getViewDefinition(string $database, string $view): string
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot read view in $database");
        }

        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT VIEW_DEFINITION
             FROM information_schema.VIEWS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :view"
        );
        $stmt->execute([':db' => $database, ':view' => $view]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("View not found: $database.$view");
        }
        return trim((string)($row['VIEW_DEFINITION'] ?? ''));
    }

    public function upsertView(string $database, string $view, string $definition): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot save view in $database");
        }

        $this->ensureConnected();
        $database = $this->validateIdent($database);
        $name = $this->validateIdent($view);
        $body = trim($definition);
        if ($body === '') {
            throw new RuntimeException('View definition is empty');
        }

        $qDb = $this->quoteIdent($database);
        $qView = $this->quoteIdent($name);

        // MySQL resolves unqualified identifiers in the view body against the
        // active catalog. Set it explicitly so CREATE VIEW works even when the
        // connection itself was opened without dbname.
        $this->pdo->exec("USE $qDb");

        if (preg_match('/^\s*CREATE\b/i', $body)) {
            // Full DDL (with ALGORITHM, DEFINER, SQL SECURITY, etc.) provided by the frontend.
            $this->pdo->exec($body);
        } else {
            if (!preg_match('/^\s*(SELECT|WITH)\b/i', $body)) {
                throw new RuntimeException('View definition must be a SELECT or CREATE VIEW statement.');
            }
            if (preg_match('/;\s*\S/', rtrim($body, "; \t\n\r"))) {
                throw new RuntimeException('View definition must be a single statement.');
            }
            $this->pdo->exec("CREATE OR REPLACE VIEW $qDb.$qView AS\n$body");
        }
    }

    public function dropView(string $database, string $view): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot drop view in $database");
        }

        $this->ensureConnected();
        $name  = $this->validateIdent($view);
        $qDb   = $this->quoteIdent($database);
        $qView = $this->quoteIdent($name);
        $this->pdo->exec("DROP VIEW IF EXISTS $qDb.$qView");
    }
}
