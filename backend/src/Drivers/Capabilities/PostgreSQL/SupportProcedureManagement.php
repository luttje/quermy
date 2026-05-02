<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportProcedureManagement
{
    public function listProcedures(string $database): array
    {
        $this->ensureConnected();
        $stmt = $this->pdo->query(
            "SELECT p.proname AS name
             FROM pg_proc p
             JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'public' AND p.prokind = 'p'
             ORDER BY p.proname"
        );
        return array_map(static fn($r) => $r['name'], $stmt->fetchAll());
    }

    public function getProcedureDefinition(string $database, string $procedure): string
    {
        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT pg_get_proceduredef(p.oid) AS def
             FROM pg_proc p
             JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'public' AND p.proname = :name AND p.prokind = 'p'
             LIMIT 1"
        );
        $stmt->execute([':name' => $procedure]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Procedure not found: $procedure");
        }
        return trim((string)($row['def'] ?? ''));
    }

    public function upsertProcedure(string $database, string $procedure, string $definition): void
    {
        $this->ensureConnected();
        $body = trim($definition);
        if ($body === '') {
            throw new RuntimeException('Procedure definition is empty');
        }
        if (!preg_match('/^\s*CREATE\b/i', $body)) {
            throw new RuntimeException('Procedure definition must start with CREATE PROCEDURE.');
        }
        // Drop all overloads of this procedure name before re-creating.
        $stmt = $this->pdo->prepare(
            "SELECT p.oid::regprocedure AS sig
             FROM pg_proc p
             JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'public' AND p.proname = :name AND p.prokind = 'p'"
        );
        $stmt->execute([':name' => $procedure]);
        foreach ($stmt->fetchAll() as $row) {
            $sig = $row['sig'];
            $this->pdo->exec("DROP PROCEDURE IF EXISTS $sig CASCADE");
        }
        $this->pdo->exec($body);
    }

    public function dropProcedure(string $database, string $procedure): void
    {
        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT p.oid::regprocedure AS sig
             FROM pg_proc p
             JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'public' AND p.proname = :name AND p.prokind = 'p'"
        );
        $stmt->execute([':name' => $procedure]);
        foreach ($stmt->fetchAll() as $row) {
            $sig = $row['sig'];
            $this->pdo->exec("DROP PROCEDURE IF EXISTS $sig CASCADE");
        }
    }
}
