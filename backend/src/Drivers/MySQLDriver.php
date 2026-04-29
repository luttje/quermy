<?php declare(strict_types=1);

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
        $ai   = !empty($definition['autoIncrement']) ? ' AUTO_INCREMENT' : '';
        // AUTO_INCREMENT columns must not have a DEFAULT clause
        $def  = empty($definition['autoIncrement'])
                    ? (isset($definition['default']) && $definition['default'] !== null
                        ? ' DEFAULT ' . $this->pdo->quote((string)$definition['default'])
                        : (($definition['nullable'] ?? true) ? ' DEFAULT NULL' : ''))
                    : '';
        $after = !empty($definition['after'])
                    ? ' AFTER ' . $this->quoteIdent($definition['after'])
                    : '';
        $this->pdo->exec("ALTER TABLE $qDb.$qTbl ADD COLUMN $qCol $type$null$def$ai$after");
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
        // AUTO_INCREMENT columns must not have a DEFAULT clause
        $ai  = !empty($definition['autoIncrement']) ? ' AUTO_INCREMENT' : '';
        $def  = empty($definition['autoIncrement'])
                    ? (isset($definition['default']) && $definition['default'] !== null
                        ? ' DEFAULT ' . $this->pdo->quote((string)$definition['default'])
                        : (($definition['nullable'] ?? true) ? ' DEFAULT NULL' : ''))
                    : '';
        $this->pdo->exec("ALTER TABLE $qDb.$qTbl CHANGE COLUMN $qOld $qNew $type$null$def$ai");
    }

    public function dropColumn(string $database, string $table, string $columnName): void
    {
        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($columnName);
        $this->pdo->exec("ALTER TABLE $qDb.$qTbl DROP COLUMN $qCol");
    }

    public function describeTable(string $database, string $table): array
    {
        $this->ensureConnected();

        // Columns (using parameter binding — no need to validateIdent here
        // because the values are bound, not interpolated).
        $cstmt = $this->pdo->prepare(
            "SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY,
                    COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT
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
                'key'      => $c['COLUMN_KEY'] ?? '',
                'default'  => $c['COLUMN_DEFAULT'],
                'extra'    => $c['EXTRA'] ?? '',
                'comment'  => $c['COLUMN_COMMENT'] ?? '',
            ];
        }

        if ($columns === []) {
            // Either the table doesn't exist or the user can't see it — be
            // explicit, otherwise the LLM gets back an empty struct and
            // happily writes a query against a non-existent table.
            throw new RuntimeException("Table not found or not accessible: $database.$table");
        }

        // Indexes — group rows by INDEX_NAME, preserving SEQ_IN_INDEX order.
        $istmt = $this->pdo->prepare(
            "SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl
             ORDER BY INDEX_NAME, SEQ_IN_INDEX"
        );
        $istmt->execute([':db' => $database, ':tbl' => $table]);

        $byIndex = [];
        foreach ($istmt->fetchAll() as $r) {
            $name = $r['INDEX_NAME'];
            if (!isset($byIndex[$name])) {
                $byIndex[$name] = [
                    'name'    => $name,
                    'columns' => [],
                    'unique'  => ((int)$r['NON_UNIQUE']) === 0,
                ];
            }
            $byIndex[$name]['columns'][] = $r['COLUMN_NAME'];
        }

        $primaryKey = isset($byIndex['PRIMARY']) ? $byIndex['PRIMARY']['columns'] : [];
        $indexes    = array_values($byIndex);

        return [
            'columns'    => $columns,
            'primaryKey' => $primaryKey,
            'indexes'    => $indexes,
        ];
    }

    public function getForeignKeys(string $database, string $table): array
    {
        $this->ensureConnected();

        // Outgoing: this table → others.
        $outStmt = $this->pdo->prepare(
            "SELECT k.COLUMN_NAME, k.REFERENCED_TABLE_SCHEMA, k.REFERENCED_TABLE_NAME,
                    k.REFERENCED_COLUMN_NAME, k.CONSTRAINT_NAME,
                    r.UPDATE_RULE, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = :db
               AND k.TABLE_NAME   = :tbl
               AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION"
        );
        $outStmt->execute([':db' => $database, ':tbl' => $table]);
        $outgoing = [];
        foreach ($outStmt->fetchAll() as $r) {
            $outgoing[] = [
                'column'             => $r['COLUMN_NAME'],
                'referencedDatabase' => $r['REFERENCED_TABLE_SCHEMA'],
                'referencedTable'    => $r['REFERENCED_TABLE_NAME'],
                'referencedColumn'   => $r['REFERENCED_COLUMN_NAME'],
                'constraintName'     => $r['CONSTRAINT_NAME'],
                'onUpdate'           => $r['UPDATE_RULE'],
                'onDelete'           => $r['DELETE_RULE'],
            ];
        }

        // Incoming: others → this table.
        $inStmt = $this->pdo->prepare(
            "SELECT k.TABLE_SCHEMA  AS REFERENCING_SCHEMA,
                    k.TABLE_NAME    AS REFERENCING_TABLE,
                    k.COLUMN_NAME   AS REFERENCING_COLUMN,
                    k.REFERENCED_COLUMN_NAME,
                    k.CONSTRAINT_NAME,
                    r.UPDATE_RULE, r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.CONSTRAINT_NAME   = k.CONSTRAINT_NAME
             WHERE k.REFERENCED_TABLE_SCHEMA = :db
               AND k.REFERENCED_TABLE_NAME   = :tbl
             ORDER BY k.TABLE_SCHEMA, k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION"
        );
        $inStmt->execute([':db' => $database, ':tbl' => $table]);
        $incoming = [];
        foreach ($inStmt->fetchAll() as $r) {
            $incoming[] = [
                'column'              => $r['REFERENCED_COLUMN_NAME'],
                'referencingDatabase' => $r['REFERENCING_SCHEMA'],
                'referencingTable'    => $r['REFERENCING_TABLE'],
                'referencingColumn'   => $r['REFERENCING_COLUMN'],
                'constraintName'      => $r['CONSTRAINT_NAME'],
                'onUpdate'            => $r['UPDATE_RULE'],
                'onDelete'            => $r['DELETE_RULE'],
            ];
        }

        return [
            'outgoing' => $outgoing,
            'incoming' => $incoming,
        ];
    }

    public function sampleTable(string $database, string $table, int $limit): array
    {
        $this->ensureConnected();
        // Identifier interpolation requires hard validation.
        $database = $this->validateIdent($database);
        $table    = $this->validateIdent($table);
        $limit    = max(1, min(20, $limit));

        $stmt = $this->pdo->query("SELECT * FROM `$database`.`$table` LIMIT $limit");
        $rows = $stmt->fetchAll();

        $columns = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta = $stmt->getColumnMeta($i) ?: [];
            $columns[] = $meta['name'] ?? "col_$i";
        }

        // We can only know "truncated" cheaply by checking whether we got
        // exactly $limit rows back. False negatives are possible (table
        // might happen to have exactly $limit rows) but that's harmless —
        // the agent treats this as a hint, not a guarantee.
        $truncated = count($rows) >= $limit;

        return [
            'columns'   => $columns,
            'rows'      => $rows,
            'truncated' => $truncated,
        ];
    }

    public function searchSchema(string $database, string $term, string $scope): array
    {
        $this->ensureConnected();

        $like   = '%' . $this->escapeLike($term) . '%';
        $tables  = [];
        $columns = [];

        if ($scope === 'tables' || $scope === 'all') {
            $stmt = $this->pdo->prepare(
                "SELECT TABLE_NAME, TABLE_COMMENT
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = :db
                   AND TABLE_TYPE = 'BASE TABLE'
                   AND (TABLE_NAME LIKE :term ESCAPE '\\\\'
                        OR TABLE_COMMENT LIKE :term2 ESCAPE '\\\\')
                 ORDER BY TABLE_NAME
                 LIMIT 100"
            );
            $stmt->execute([':db' => $database, ':term' => $like, ':term2' => $like]);
            foreach ($stmt->fetchAll() as $r) {
                $tables[] = [
                    'name'    => $r['TABLE_NAME'],
                    'comment' => $r['TABLE_COMMENT'] ?? '',
                ];
            }
        }

        if ($scope === 'columns' || $scope === 'all') {
            $stmt = $this->pdo->prepare(
                "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, COLUMN_COMMENT
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = :db
                   AND (COLUMN_NAME LIKE :term ESCAPE '\\\\'
                        OR COLUMN_COMMENT LIKE :term2 ESCAPE '\\\\')
                 ORDER BY TABLE_NAME, ORDINAL_POSITION
                 LIMIT 200"
            );
            $stmt->execute([':db' => $database, ':term' => $like, ':term2' => $like]);
            foreach ($stmt->fetchAll() as $r) {
                $columns[] = [
                    'table'   => $r['TABLE_NAME'],
                    'name'    => $r['COLUMN_NAME'],
                    'type'    => $r['COLUMN_TYPE'],
                    'comment' => $r['COLUMN_COMMENT'] ?? '',
                ];
            }
        }

        return ['tables' => $tables, 'columns' => $columns];
    }

    public function getCreateTable(string $database, string $table): string
    {
        $this->ensureConnected();
        $database = $this->validateIdent($database);
        $table    = $this->validateIdent($table);

        $stmt = $this->pdo->query("SHOW CREATE TABLE `$database`.`$table`");
        $row  = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Table not found: $database.$table");
        }
        // SHOW CREATE TABLE returns either ['Table' => ..., 'Create Table' => DDL]
        // or for views ['View' => ..., 'Create View' => DDL]. Pick whichever
        // "Create *" key is present.
        foreach ($row as $key => $val) {
            if (str_starts_with((string)$key, 'Create ')) {
                return (string)$val;
            }
        }
        throw new RuntimeException('Unexpected SHOW CREATE TABLE output.');
    }

    public function explainQuery(string $database, string $sql): array
    {
        $this->ensureConnected();

        // Defense in depth — the tool already checks this, but the driver
        // is the last line of defense and shouldn't trust its caller.
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            throw new RuntimeException('explainQuery only accepts SELECT statements.');
        }
        // Reject multi-statement payloads. We can't fully parse SQL with a
        // regex but we can refuse the obvious case where someone tries to
        // chain a destructive statement after the SELECT.
        if (preg_match('/;\s*\S/', rtrim($sql, "; \t\n\r"))) {
            throw new RuntimeException('explainQuery accepts only a single statement.');
        }

        if ($database !== '') {
            $database = $this->validateIdent($database);
            $this->pdo->exec("USE `$database`");
        }

        // Plain (tabular) EXPLAIN is enough for the agent — FORMAT=JSON is
        // richer but bulkier and harder for the LLM to read.
        $stmt = $this->pdo->query('EXPLAIN ' . $sql);
        return $stmt->fetchAll();
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

    /**
     * Escape user input for use inside a LIKE pattern. The pattern itself
     * (% and _ wildcards) is added by the caller; this strips those out
     * of the user's term so a search for "user_id" doesn't match
     * "userXid".
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
