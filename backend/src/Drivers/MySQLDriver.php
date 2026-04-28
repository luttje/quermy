<?php
declare(strict_types=1);

namespace Quermy\Drivers;

use PDO;
use PDOException;
use RuntimeException;

class MySQLDriver implements DriverInterface
{
    private ?PDO $pdo = null;
    private ?string $currentDb = null;

    public static function engineId(): string
    {
        return 'mysql';
    }

    public function connect(array $config): void
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int)($config['port'] ?? 3306);
        $user = $config['username'] ?? '';
        $pass = $config['password'] ?? '';
        $db   = $config['database'] ?? null;

        $dsnParts = ["host=$host", "port=$port", "charset=utf8mb4"];
        if ($db) {
            $dsnParts[] = "dbname=$db";
            $this->currentDb = $db;
        }
        $dsn = 'mysql:' . implode(';', $dsnParts);

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
        } catch (PDOException $e) {
            // Don't leak the DSN/password — only the message.
            throw new RuntimeException('Could not connect: ' . $e->getMessage(), 0, $e);
        }
    }

    public function disconnect(): void
    {
        $this->pdo = null;
        $this->currentDb = null;
    }

    public function listDatabases(): array
    {
        $this->ensureConnected();
        $stmt = $this->pdo->query(
            "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA
             WHERE SCHEMA_NAME NOT IN ('mysql','information_schema','performance_schema','sys')
             ORDER BY SCHEMA_NAME"
        );
        return array_map(static fn($r) => $r['SCHEMA_NAME'], $stmt->fetchAll());
    }

    public function listTables(string $database): array
    {
        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH + INDEX_LENGTH AS SIZE
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'
             ORDER BY TABLE_NAME"
        );
        $stmt->execute([':db' => $database]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'name' => $row['TABLE_NAME'],
                'rows' => $row['TABLE_ROWS'] !== null ? (int)$row['TABLE_ROWS'] : null,
                'size' => $row['SIZE'] !== null ? (int)$row['SIZE'] : null,
            ];
        }
        return $out;
    }

    public function browseTable(string $database, string $table, int $limit, int $offset): array
    {
        $this->ensureConnected();
        // Identifiers can't be parameter-bound. Sanitize hard: only allow
        // valid MySQL identifier chars to defeat injection through the path.
        $database = $this->validateIdent($database);
        $table    = $this->validateIdent($table);

        // Column metadata
        $cstmt = $this->pdo->prepare(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, COLUMN_DEFAULT, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl
             ORDER BY ORDINAL_POSITION"
        );
        $cstmt->execute([':db' => $database, ':tbl' => $table]);
        $columns = [];
        foreach ($cstmt->fetchAll() as $c) {
            $columns[] = [
                'name'     => $c['COLUMN_NAME'],
                'type'     => $c['COLUMN_TYPE'],
                'nullable' => $c['IS_NULLABLE'] === 'YES',
                'key'      => $c['COLUMN_KEY'],
                'default'  => $c['COLUMN_DEFAULT'],
                'extra'    => $c['EXTRA'],
            ];
        }

        // Total rows
        $totalStmt = $this->pdo->query("SELECT COUNT(*) AS c FROM `$database`.`$table`");
        $total = (int)$totalStmt->fetch()['c'];

        // Data — limit and offset go through PDO bind, but they need cast to int
        $limit  = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $rstmt = $this->pdo->query("SELECT * FROM `$database`.`$table` LIMIT $limit OFFSET $offset");
        $rows = $rstmt->fetchAll();

        return [
            'columns' => $columns,
            'rows'    => $rows,
            'total'   => $total,
        ];
    }

    public function runQuery(string $database, string $sql): array
    {
        $this->ensureConnected();
        if ($database !== '') {
            $database = $this->validateIdent($database);
            $this->pdo->exec("USE `$database`");
        }

        $start = microtime(true);
        $stmt  = $this->pdo->query($sql);
        $duration = (microtime(true) - $start) * 1000.0;

        // SELECT-style if columnCount > 0
        $isSelect = $stmt->columnCount() > 0;
        $rows = $isSelect ? $stmt->fetchAll() : [];

        $columns = [];
        if ($isSelect) {
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i) ?: [];
                $columns[] = [
                    'name' => $meta['name'] ?? "col_$i",
                    'type' => $meta['native_type'] ?? ($meta['mysql:decl_type'] ?? 'unknown'),
                ];
            }
        }

        return [
            'columns'    => $columns,
            'rows'       => $rows,
            'affected'   => $isSelect ? count($rows) : $stmt->rowCount(),
            'isSelect'   => $isSelect,
            'durationMs' => round($duration, 2),
        ];
    }

    public function insertRow(string $database, string $table, array $values): array
    {
        $this->ensureConnected();
        if (empty($values)) {
            throw new RuntimeException('No values provided for insert');
        }
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        $cols  = implode(', ', array_map([$this, 'quoteIdent'], array_keys($values)));
        $phs   = implode(', ', array_fill(0, count($values), '?'));
        $stmt  = $this->pdo->prepare("INSERT INTO $qDb.$qTbl ($cols) VALUES ($phs)");
        $stmt->execute(array_values($values));
        return ['affected' => $stmt->rowCount(), 'insertId' => (int)$this->pdo->lastInsertId()];
    }

    public function updateRow(string $database, string $table, array $where, array $values): array
    {
        $this->ensureConnected();
        if (empty($where))  throw new RuntimeException('No WHERE conditions provided');
        if (empty($values)) throw new RuntimeException('No values to update');

        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);

        $setClauses   = [];
        $whereClauses = [];
        $params       = [];

        foreach ($values as $col => $val) {
            $setClauses[] = $this->quoteIdent($col) . ' = ?';
            $params[]     = $val;
        }
        foreach ($where as $col => $val) {
            if ($val === null) {
                $whereClauses[] = $this->quoteIdent($col) . ' IS NULL';
            } else {
                $whereClauses[] = $this->quoteIdent($col) . ' = ?';
                $params[]       = $val;
            }
        }

        $sql  = "UPDATE $qDb.$qTbl SET "    . implode(', ',    $setClauses)
              . " WHERE "                   . implode(' AND ', $whereClauses);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return ['affected' => $stmt->rowCount()];
    }

    public function deleteRow(string $database, string $table, array $where): array
    {
        $this->ensureConnected();
        if (empty($where)) throw new RuntimeException('No WHERE conditions provided');

        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);

        $whereClauses = [];
        $params       = [];

        foreach ($where as $col => $val) {
            if ($val === null) {
                $whereClauses[] = $this->quoteIdent($col) . ' IS NULL';
            } else {
                $whereClauses[] = $this->quoteIdent($col) . ' = ?';
                $params[]       = $val;
            }
        }

        $sql  = "DELETE FROM $qDb.$qTbl WHERE " . implode(' AND ', $whereClauses);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return ['affected' => $stmt->rowCount()];
    }

    public function addColumn(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($definition['name'] ?? '');
        $type = $this->sanitizeColumnType($definition['type'] ?? '');
        $null = ($definition['nullable'] ?? true) ? '' : ' NOT NULL';
        $def  = isset($definition['default']) && $definition['default'] !== null
                    ? ' DEFAULT ' . $this->pdo->quote((string)$definition['default'])
                    : (($definition['nullable'] ?? true) ? ' DEFAULT NULL' : '');
        $after = !empty($definition['after'])
                    ? ' AFTER ' . $this->quoteIdent($definition['after'])
                    : '';
        $this->pdo->exec("ALTER TABLE $qDb.$qTbl ADD COLUMN $qCol $type$null$def$after");
    }

    public function modifyColumn(string $database, string $table, string $columnName, array $definition): void
    {
        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        $qOld = $this->quoteIdent($columnName);
        $qNew = $this->quoteIdent($definition['name'] ?? $columnName);
        $type = $this->sanitizeColumnType($definition['type'] ?? '');
        $null = ($definition['nullable'] ?? true) ? '' : ' NOT NULL';
        $def  = isset($definition['default']) && $definition['default'] !== null
                    ? ' DEFAULT ' . $this->pdo->quote((string)$definition['default'])
                    : (($definition['nullable'] ?? true) ? ' DEFAULT NULL' : '');
        $this->pdo->exec("ALTER TABLE $qDb.$qTbl CHANGE COLUMN $qOld $qNew $type$null$def");
    }

    public function dropColumn(string $database, string $table, string $columnName): void
    {
        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($columnName);
        $this->pdo->exec("ALTER TABLE $qDb.$qTbl DROP COLUMN $qCol");
    }

    /*
     * Private helpers
     */

    private function ensureConnected(): void
    {
        if ($this->pdo === null) {
            throw new RuntimeException('Not connected.');
        }
    }

    /** Wrap an identifier in backticks, escaping embedded backticks. */
    private function quoteIdent(string $name): string
    {
        if ($name === '') throw new RuntimeException('Empty identifier');
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** Allow only [A-Za-z0-9_$] — what MySQL accepts for unquoted idents. */
    private function validateIdent(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_$]+$/', $name)) {
            throw new RuntimeException("Invalid identifier: $name");
        }
        return $name;
    }

    /** Validate a MySQL column type string (e.g. "VARCHAR(255)", "INT UNSIGNED"). */
    private function sanitizeColumnType(string $type): string
    {
        $type = trim($type);
        if ($type === '') throw new RuntimeException('Column type cannot be empty');
        if (!preg_match('/^[A-Za-z0-9_()\s,]+$/', $type)) {
            throw new RuntimeException('Invalid column type');
        }
        if (strlen($type) > 100) throw new RuntimeException('Column type too long');
        return strtoupper($type);
    }
}
