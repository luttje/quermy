<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\SQLServer;

use RuntimeException;

/** @mixin \Quermy\Drivers\SQLServerDriver */
trait SupportProcedureManagement
{
    public function listProcedures(string $database): array
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $stmt = $this->pdo->query(
            "SELECT p.name
             FROM sys.procedures p
             JOIN sys.schemas s ON s.schema_id = p.schema_id
             WHERE s.name = 'dbo'
             ORDER BY p.name"
        );
        return array_map(static fn($r) => $r['name'], $stmt->fetchAll());
    }

    public function getProcedureDefinition(string $database, string $procedure): string
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $stmt = $this->pdo->prepare(
            "SELECT m.definition
             FROM sys.procedures p
             JOIN sys.schemas s ON s.schema_id = p.schema_id
             JOIN sys.sql_modules m ON m.object_id = p.object_id
             WHERE s.name = 'dbo' AND p.name = :name"
        );
        $stmt->execute([':name' => $procedure]);
        $row = $stmt->fetch();
        if (!$row || !isset($row['definition'])) {
            throw new RuntimeException("Procedure not found: $procedure");
        }
        return trim((string)$row['definition']);
    }

    public function upsertProcedure(string $database, string $procedure, string $definition): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $name    = $this->validateIdent($procedure);
        $body    = trim($definition);
        if ($body === '') {
            throw new RuntimeException('Procedure definition is empty');
        }
        if (!preg_match('/^\s*CREATE\b/i', $body)) {
            throw new RuntimeException('Procedure definition must start with CREATE PROCEDURE.');
        }
        $qProc   = $this->quoteIdent($name);
        $procLit = $this->pdo->quote('dbo.' . $name);
        // CREATE PROCEDURE must be the only statement in a batch.
        $this->pdo->exec("IF OBJECT_ID($procLit, 'P') IS NOT NULL DROP PROCEDURE dbo.$qProc");
        $this->pdo->exec($body);
    }

    public function dropProcedure(string $database, string $procedure): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $name    = $this->validateIdent($procedure);
        $qProc   = $this->quoteIdent($name);
        $procLit = $this->pdo->quote('dbo.' . $name);
        $this->pdo->exec("IF OBJECT_ID($procLit, 'P') IS NOT NULL DROP PROCEDURE dbo.$qProc");
    }
}
