<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportViewManagement
{
    public function listViews(string $database): array
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $stmt = $this->pdo->query(
            "SELECT v.name
             FROM sys.views v
             JOIN sys.schemas s ON s.schema_id = v.schema_id
             WHERE s.name = 'dbo'
             ORDER BY v.name"
        );
        return array_map(static fn($r) => $r['name'], $stmt->fetchAll());
    }

    public function getViewDefinition(string $database, string $view): string
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $stmt = $this->pdo->prepare(
            "SELECT m.definition
             FROM sys.views v
             JOIN sys.schemas s ON s.schema_id = v.schema_id
             JOIN sys.sql_modules m ON m.object_id = v.object_id
             WHERE s.name = 'dbo' AND v.name = :view"
        );
        $stmt->execute([':view' => $view]);
        $row = $stmt->fetch();
        if (!$row || !isset($row['definition'])) {
            throw new RuntimeException("View not found: $view");
        }
        return $this->extractViewBody((string)$row['definition']);
    }

    public function upsertView(string $database, string $view, string $definition): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $name = $this->validateIdent($view);
        $body = trim($definition);
        if ($body === '') {
            throw new RuntimeException('View definition is empty');
        }
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $body)) {
            throw new RuntimeException('View definition must be a SELECT statement.');
        }
        if (preg_match('/;\s*\S/', rtrim($body, "; \t\n\r"))) {
            throw new RuntimeException('View definition must be a single statement.');
        }
        $qView     = $this->quoteIdent($name);
        $viewName  = 'dbo.' . $name;
        $viewLit   = $this->pdo->quote($viewName);

        // CREATE VIEW must be the first statement in a batch, so we do
        // existence check/drop and create in separate exec() calls.
        $this->pdo->exec("IF OBJECT_ID($viewLit, 'V') IS NOT NULL DROP VIEW dbo.$qView");
        $this->pdo->exec("CREATE VIEW dbo.$qView AS\n$body");
    }

    public function dropView(string $database, string $view): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $name      = $this->validateIdent($view);
        $qView     = $this->quoteIdent($name);
        $viewName  = 'dbo.' . $name;
        $viewLit   = $this->pdo->quote($viewName);
        $this->pdo->exec("IF OBJECT_ID($viewLit, 'V') IS NOT NULL DROP VIEW dbo.$qView");
    }
}
