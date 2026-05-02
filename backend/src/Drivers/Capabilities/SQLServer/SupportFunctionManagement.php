<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportFunctionManagement
{
    public function listFunctions(string $database): array
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $stmt = $this->pdo->query(
            "SELECT o.name
             FROM sys.objects o
             JOIN sys.schemas s ON s.schema_id = o.schema_id
             WHERE s.name = 'dbo' AND o.type IN ('FN', 'IF', 'TF')
             ORDER BY o.name"
        );
        return array_map(static fn($r) => $r['name'], $stmt->fetchAll());
    }

    public function getFunctionDefinition(string $database, string $function): string
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $stmt = $this->pdo->prepare(
            "SELECT m.definition
             FROM sys.objects o
             JOIN sys.schemas s ON s.schema_id = o.schema_id
             JOIN sys.sql_modules m ON m.object_id = o.object_id
             WHERE s.name = 'dbo' AND o.name = :name AND o.type IN ('FN', 'IF', 'TF')"
        );
        $stmt->execute([':name' => $function]);
        $row = $stmt->fetch();
        if (!$row || !isset($row['definition'])) {
            throw new RuntimeException("Function not found: $function");
        }
        return trim((string)$row['definition']);
    }

    public function upsertFunction(string $database, string $function, string $definition): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $name    = $this->validateIdent($function);
        $body    = trim($definition);
        if ($body === '') {
            throw new RuntimeException('Function definition is empty');
        }
        if (!preg_match('/^\s*CREATE\b/i', $body)) {
            throw new RuntimeException('Function definition must start with CREATE FUNCTION.');
        }
        $qFunc   = $this->quoteIdent($name);
        $funcLit = $this->pdo->quote('dbo.' . $name);
        // Drop scalar, inline-TVF, and multi-statement TVF types.
        $this->pdo->exec(
            "IF OBJECT_ID($funcLit, 'FN') IS NOT NULL OR OBJECT_ID($funcLit, 'IF') IS NOT NULL OR OBJECT_ID($funcLit, 'TF') IS NOT NULL DROP FUNCTION dbo.$qFunc"
        );
        $this->pdo->exec($body);
    }

    public function dropFunction(string $database, string $function): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $name    = $this->validateIdent($function);
        $qFunc   = $this->quoteIdent($name);
        $funcLit = $this->pdo->quote('dbo.' . $name);
        $this->pdo->exec(
            "IF OBJECT_ID($funcLit, 'FN') IS NOT NULL OR OBJECT_ID($funcLit, 'IF') IS NOT NULL OR OBJECT_ID($funcLit, 'TF') IS NOT NULL DROP FUNCTION dbo.$qFunc"
        );
    }
}
