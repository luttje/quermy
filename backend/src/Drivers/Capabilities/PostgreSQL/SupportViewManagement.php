<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportViewManagement
{
    public function listTables(string $database): array
    {
        $this->ensureConnected();
        // In PostgreSQL the "database" is the connection target; we list
        // tables in the 'public' schema of the currently-connected database.
        $stmt = $this->pdo->query(
            "SELECT relname AS table_name,
                    GREATEST(reltuples::BIGINT, 0) AS row_estimate,
                    pg_total_relation_size(oid) AS total_size
             FROM pg_class
             WHERE relkind = 'r'
               AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = 'public')
             ORDER BY relname"
        );
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'name' => $row['table_name'],
                'rows' => (int)$row['row_estimate'],
                'size' => (int)$row['total_size'],
            ];
        }
        return $out;
    }

    public function listViews(string $database): array
    {
        $this->ensureConnected();
        $stmt = $this->pdo->query(
            "SELECT table_name
             FROM information_schema.views
             WHERE table_schema = 'public'
             ORDER BY table_name"
        );
        return array_map(static fn($r) => $r['table_name'], $stmt->fetchAll());
    }

    public function getViewDefinition(string $database, string $view): string
    {
        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT view_definition
             FROM information_schema.views
             WHERE table_schema = 'public' AND table_name = :view"
        );
        $stmt->execute([':view' => $view]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("View not found: $view");
        }
        return trim((string)($row['view_definition'] ?? ''));
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
            // Full DDL (including MATERIALIZED VIEW, security_barrier, etc.) provided by the frontend.
            // Materialized views don't support OR REPLACE — drop first.
            if (preg_match('/^\s*CREATE\s+MATERIALIZED\b/i', $body)) {
                $this->pdo->exec("DROP MATERIALIZED VIEW IF EXISTS public.$qView");
            }
            $this->pdo->exec($body);
        } else {
            if (!preg_match('/^\s*(SELECT|WITH)\b/i', $body)) {
                throw new RuntimeException('View definition must be a SELECT or CREATE VIEW statement.');
            }
            if (preg_match('/;\s*\S/', rtrim($body, "; \t\n\r"))) {
                throw new RuntimeException('View definition must be a single statement.');
            }
            $this->pdo->exec("CREATE OR REPLACE VIEW public.$qView AS\n$body");
        }
    }

    public function dropView(string $database, string $view): void
    {
        $this->ensureConnected();
        $name  = $this->validateIdent($view);
        $qView = $this->quoteIdent($name);
        $this->pdo->exec("DROP VIEW IF EXISTS public.$qView");
    }
}
