<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\MySQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\MySQLDriver */
trait SupportProcedureManagement
{
    public function listProcedures(string $database): array
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot list procedures for $database");
        }

        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT ROUTINE_NAME
             FROM information_schema.ROUTINES
             WHERE ROUTINE_SCHEMA = :db AND ROUTINE_TYPE = 'PROCEDURE'
             ORDER BY ROUTINE_NAME"
        );
        $stmt->execute([':db' => $database]);
        return array_map(static fn($r) => $r['ROUTINE_NAME'], $stmt->fetchAll());
    }

    public function getProcedureDefinition(string $database, string $procedure): string
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot read procedure in $database");
        }

        $this->ensureConnected();
        $qDb   = $this->quoteIdent($this->validateIdent($database));
        $qProc = $this->quoteIdent($procedure);
        $stmt  = $this->pdo->query("SHOW CREATE PROCEDURE $qDb.$qProc");
        $row   = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Procedure not found: $database.$procedure");
        }
        return trim((string)($row['Create Procedure'] ?? ''));
    }

    public function upsertProcedure(string $database, string $procedure, string $definition): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot save procedure in $database");
        }

        $this->ensureConnected();
        $name = $this->validateIdent($procedure);
        $body = trim($definition);
        if ($body === '') {
            throw new RuntimeException('Procedure definition is empty');
        }
        if (!preg_match('/^\s*CREATE\b/i', $body)) {
            throw new RuntimeException('Procedure definition must start with CREATE PROCEDURE.');
        }

        $qDb   = $this->quoteIdent($database);
        $qProc = $this->quoteIdent($name);
        $this->pdo->exec("USE $qDb");
        $this->pdo->exec("DROP PROCEDURE IF EXISTS $qDb.$qProc");
        $this->pdo->exec($body);
    }

    public function dropProcedure(string $database, string $procedure): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot drop procedure in $database");
        }

        $this->ensureConnected();
        $name  = $this->validateIdent($procedure);
        $qDb   = $this->quoteIdent($database);
        $qProc = $this->quoteIdent($name);
        $this->pdo->exec("DROP PROCEDURE IF EXISTS $qDb.$qProc");
    }
}
