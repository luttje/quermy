<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportFunctionManagement
{
    public function listFunctions(string $database): array
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot list functions for $database");
        }

        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT ROUTINE_NAME
             FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = :db AND ROUTINE_TYPE = 'FUNCTION'
             ORDER BY ROUTINE_NAME"
        );
        $stmt->execute([':db' => $database]);
        return array_map(static fn($r) => $r['ROUTINE_NAME'], $stmt->fetchAll());
    }

    public function getFunctionDefinition(string $database, string $function): string
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot read function in $database");
        }

        $this->ensureConnected();
        $qDb   = $this->quoteIdent($this->validateIdent($database));
        $qFunc = $this->quoteIdent($function);
        $stmt  = $this->pdo->query("SHOW CREATE FUNCTION $qDb.$qFunc");
        $row   = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Function not found: $database.$function");
        }
        return trim((string)($row['Create Function'] ?? ''));
    }

    public function upsertFunction(string $database, string $function, string $definition): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot save function in $database");
        }

        $this->ensureConnected();
        $name = $this->validateIdent($function);
        $body = trim($definition);
        if ($body === '') {
            throw new RuntimeException('Function definition is empty');
        }
        if (!preg_match('/^\s*CREATE\b/i', $body)) {
            throw new RuntimeException('Function definition must start with CREATE FUNCTION.');
        }

        $qDb   = $this->quoteIdent($database);
        $qFunc = $this->quoteIdent($name);
        $this->pdo->exec("USE $qDb");
        $this->pdo->exec("DROP FUNCTION IF EXISTS $qDb.$qFunc");
        $this->pdo->exec($body);
    }

    public function dropFunction(string $database, string $function): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot drop function in $database");
        }

        $this->ensureConnected();
        $name  = $this->validateIdent($function);
        $qDb   = $this->quoteIdent($database);
        $qFunc = $this->quoteIdent($name);
        $this->pdo->exec("DROP FUNCTION IF EXISTS $qDb.$qFunc");
    }
}
