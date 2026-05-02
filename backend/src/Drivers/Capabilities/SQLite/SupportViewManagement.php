<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLite;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLiteDriver */
trait SupportViewManagement
{
    public function listViews(string $database): array
    {
        $this->ensureConnected();
        $stmt = $this->pdo->query(
            "SELECT name
             FROM sqlite_master
             WHERE type = 'view' AND name NOT LIKE 'sqlite_%'
             ORDER BY name"
        );
        return array_map(static fn($r) => $r['name'], $stmt->fetchAll());
    }

    public function getViewDefinition(string $database, string $view): string
    {
        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT sql
             FROM sqlite_master
             WHERE type = 'view' AND name = :view"
        );
        $stmt->execute([':view' => $view]);
        $row = $stmt->fetch();
        if (!$row || !isset($row['sql'])) {
            throw new RuntimeException("View not found: $view");
        }

        $sql = trim((string)$row['sql']);
        if (preg_match('/\bAS\b(.*)$/is', $sql, $m)) {
            return trim($m[1]);
        }
        return $sql;
    }

    public function upsertView(string $database, string $view, string $definition): void
    {
        $this->ensureConnected();
        $name = $this->validateIdent($view);
        $body = trim($definition);
        if ($body === '') {
            throw new RuntimeException('View definition is empty');
        }
        $qView = $this->quoteIdent($name);
        if (preg_match('/^\s*CREATE\b/i', $body)) {
            // Full DDL provided by the frontend — drop first since SQLite has no OR REPLACE for views.
            $this->pdo->exec("DROP VIEW IF EXISTS $qView");
            $this->pdo->exec($body);
        } else {
            if (!preg_match('/^\s*(SELECT|WITH)\b/i', $body)) {
                throw new RuntimeException('View definition must be a SELECT or CREATE VIEW statement.');
            }
            if (preg_match('/;\s*\S/', rtrim($body, "; \t\n\r"))) {
                throw new RuntimeException('View definition must be a single statement.');
            }
            $this->pdo->exec("DROP VIEW IF EXISTS $qView");
            $this->pdo->exec("CREATE VIEW $qView AS\n$body");
        }
    }

    public function dropView(string $database, string $view): void
    {
        $this->ensureConnected();
        $name  = $this->validateIdent($view);
        $qView = $this->quoteIdent($name);
        $this->pdo->exec("DROP VIEW IF EXISTS $qView");
    }
}
