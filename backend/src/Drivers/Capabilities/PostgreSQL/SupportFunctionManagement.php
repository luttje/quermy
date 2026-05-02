<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportFunctionManagement
{
    public function listFunctions(string $database): array
    {
        $this->ensureConnected();
        $stmt = $this->pdo->query(
            "SELECT p.proname AS name
             FROM pg_proc p
             JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'public' AND p.prokind = 'f'
             ORDER BY p.proname"
        );
        return array_map(static fn($r) => $r['name'], $stmt->fetchAll());
    }

    public function getFunctionDefinition(string $database, string $function): string
    {
        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT pg_get_functiondef(p.oid) AS def
             FROM pg_proc p
             JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'public' AND p.proname = :name AND p.prokind = 'f'
             LIMIT 1"
        );
        $stmt->execute([':name' => $function]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Function not found: $function");
        }
        return trim((string)($row['def'] ?? ''));
    }

    public function upsertFunction(string $database, string $function, string $definition): void
    {
        $this->ensureConnected();
        $body = trim($definition);
        if ($body === '') {
            throw new RuntimeException('Function definition is empty');
        }
        if (!preg_match('/^\s*CREATE\b/i', $body)) {
            throw new RuntimeException('Function definition must start with CREATE FUNCTION.');
        }
        // Drop all overloads first to avoid conflicts when return type changes.
        $stmt = $this->pdo->prepare(
            "SELECT p.oid::regprocedure AS sig
             FROM pg_proc p
             JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'public' AND p.proname = :name AND p.prokind = 'f'"
        );
        $stmt->execute([':name' => $function]);
        foreach ($stmt->fetchAll() as $row) {
            $sig = $row['sig'];
            $this->pdo->exec("DROP FUNCTION IF EXISTS $sig CASCADE");
        }
        $this->pdo->exec($body);
    }

    public function dropFunction(string $database, string $function): void
    {
        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT p.oid::regprocedure AS sig
             FROM pg_proc p
             JOIN pg_namespace n ON n.oid = p.pronamespace
             WHERE n.nspname = 'public' AND p.proname = :name AND p.prokind = 'f'"
        );
        $stmt->execute([':name' => $function]);
        foreach ($stmt->fetchAll() as $row) {
            $sig = $row['sig'];
            $this->pdo->exec("DROP FUNCTION IF EXISTS $sig CASCADE");
        }
    }
}
