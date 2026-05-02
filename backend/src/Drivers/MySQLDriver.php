<?php declare(strict_types=1);

namespace Quermy\Drivers;

use PDO;
use PDOException;
use Quermy\Drivers\Capabilities\MySQL\SupportAddColumn;
use Quermy\Drivers\Capabilities\MySQL\SupportAlterDatabaseCollation;
use Quermy\Drivers\Capabilities\MySQL\SupportAlterTableAutoIncrement;
use Quermy\Drivers\Capabilities\MySQL\SupportAlterTableCollation;
use Quermy\Drivers\Capabilities\MySQL\SupportAlterTableEngine;
use Quermy\Drivers\Capabilities\MySQL\SupportDropColumn;
use Quermy\Drivers\Capabilities\MySQL\SupportDropDatabase;
use Quermy\Drivers\Capabilities\MySQL\SupportDropTable;
use Quermy\Drivers\Capabilities\MySQL\SupportEventManagement;
use Quermy\Drivers\Capabilities\MySQL\SupportExplain;
use Quermy\Drivers\Capabilities\MySQL\SupportForeignKeyManagement;
use Quermy\Drivers\Capabilities\MySQL\SupportForeignKeys;
use Quermy\Drivers\Capabilities\MySQL\SupportFunctionManagement;
use Quermy\Drivers\Capabilities\MySQL\SupportGetCreateTable;
use Quermy\Drivers\Capabilities\MySQL\SupportIndexManagement;
use Quermy\Drivers\Capabilities\MySQL\SupportModifyColumn;
use Quermy\Drivers\Capabilities\MySQL\SupportProcedureManagement;
use Quermy\Drivers\Capabilities\MySQL\SupportRenameDatabase;
use Quermy\Drivers\Capabilities\MySQL\SupportReorderColumn;
use Quermy\Drivers\Capabilities\MySQL\SupportTriggerManagement;
use Quermy\Drivers\Capabilities\MySQL\SupportTruncateTable;
use Quermy\Drivers\Capabilities\MySQL\SupportViewManagement;
use Quermy\Drivers\Capabilities\ProvidesColumnTypes;
use Quermy\Drivers\Capabilities\ProvidesColumnTypesWithLength;
use Quermy\Drivers\Capabilities\ProvidesDefaultColumnType;
use Quermy\Drivers\Capabilities\ProvidesListTablesQuery;
use Quermy\Drivers\Capabilities\ProvidesReferentialActions;
use Quermy\Drivers\Capabilities\ProvidesStructureQueryTemplate;
use Quermy\Drivers\Capabilities\ProvidesTableInfo;
use Quermy\Drivers\Capabilities\ProvidesTextColumnTypePatterns;
use Quermy\Drivers\Capabilities\ProvidesWelcomeQuery;
use Quermy\Drivers\Capabilities\SupportsAddColumn;
use Quermy\Drivers\Capabilities\SupportsAlterDatabaseCollation;
use Quermy\Drivers\Capabilities\SupportsAlterTableAutoIncrement;
use Quermy\Drivers\Capabilities\SupportsAlterTableCollation;
use Quermy\Drivers\Capabilities\SupportsAlterTableEngine;
use Quermy\Drivers\Capabilities\SupportsAutoIncrement;
use Quermy\Drivers\Capabilities\SupportsColumnAfter;
use Quermy\Drivers\Capabilities\SupportsDropColumn;
use Quermy\Drivers\Capabilities\SupportsDropDatabase;
use Quermy\Drivers\Capabilities\SupportsDropTable;
use Quermy\Drivers\Capabilities\SupportsEventManagement;
use Quermy\Drivers\Capabilities\SupportsExplain;
use Quermy\Drivers\Capabilities\SupportsForeignKeyBypass;
use Quermy\Drivers\Capabilities\SupportsForeignKeyManagement;
use Quermy\Drivers\Capabilities\SupportsForeignKeys;
use Quermy\Drivers\Capabilities\SupportsFunctionManagement;
use Quermy\Drivers\Capabilities\SupportsGetCreateTable;
use Quermy\Drivers\Capabilities\SupportsIndexManagement;
use Quermy\Drivers\Capabilities\SupportsModifyColumn;
use Quermy\Drivers\Capabilities\SupportsProcedureManagement;
use Quermy\Drivers\Capabilities\SupportsRenameDatabase;
use Quermy\Drivers\Capabilities\SupportsReorderColumn;
use Quermy\Drivers\Capabilities\SupportsTriggerManagement;
use Quermy\Drivers\Capabilities\SupportsTruncateTable;
use Quermy\Drivers\Capabilities\SupportsViewManagement;
use RuntimeException;

class MySQLDriver implements
    DriverInterface,
    ProvidesColumnTypes,
    ProvidesColumnTypesWithLength,
    ProvidesDefaultColumnType,
    ProvidesListTablesQuery,
    ProvidesReferentialActions,
    ProvidesStructureQueryTemplate,
    ProvidesTableInfo,
    ProvidesTextColumnTypePatterns,
    ProvidesWelcomeQuery,
    SupportsAddColumn,
    SupportsAlterDatabaseCollation,
    SupportsAlterTableAutoIncrement,
    SupportsAlterTableCollation,
    SupportsAlterTableEngine,
    SupportsAutoIncrement,
    SupportsColumnAfter,
    SupportsDropColumn,
    SupportsDropDatabase,
    SupportsDropTable,
    SupportsEventManagement,
    SupportsExplain,
    SupportsForeignKeyBypass,
    SupportsForeignKeyManagement,
    SupportsForeignKeys,
    SupportsFunctionManagement,
    SupportsGetCreateTable,
    SupportsIndexManagement,
    SupportsModifyColumn,
    SupportsProcedureManagement,
    SupportsRenameDatabase,
    SupportsReorderColumn,
    SupportsTriggerManagement,
    SupportsTruncateTable,
    SupportsViewManagement
{
    use SupportAddColumn,
        SupportAlterDatabaseCollation,
        SupportAlterTableAutoIncrement,
        SupportAlterTableCollation,
        SupportAlterTableEngine,
        SupportDropColumn,
        SupportDropDatabase,
        SupportDropTable,
        SupportEventManagement,
        SupportExplain,
        SupportForeignKeyManagement,
        SupportForeignKeys,
        SupportFunctionManagement,
        SupportGetCreateTable,
        SupportIndexManagement,
        SupportModifyColumn,
        SupportProcedureManagement,
        SupportRenameDatabase,
        SupportReorderColumn,
        SupportTriggerManagement,
        SupportTruncateTable,
        SupportViewManagement;

    private ?PDO $pdo = null;
    private ?string $pinnedDatabaseName = null;

    public static function engineId(): string
    {
        return 'mysql';
    }

    public static function engineMeta(): array
    {
        return [
            'id'              => 'mysql',
            'label'           => 'MySQL',
            'defaultPort'     => 3306,
            'defaultUsername' => 'root',
            'connectionType'  => 'tcp',
            'identifierOpen'  => '`',
            'identifierClose' => '`',
        ];
    }

    public function columnTypes(): array
    {
        return [
            // Numeric
            'INT', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT',
            'INT UNSIGNED', 'TINYINT UNSIGNED', 'SMALLINT UNSIGNED',
            'MEDIUMINT UNSIGNED', 'BIGINT UNSIGNED',
            'DECIMAL', 'FLOAT', 'DOUBLE', 'BIT',
            // String
            'CHAR', 'VARCHAR', 'TINYTEXT', 'TEXT',
            'MEDIUMTEXT', 'LONGTEXT',
            'BINARY', 'VARBINARY',
            'TINYBLOB', 'BLOB', 'MEDIUMBLOB', 'LONGBLOB',
            'ENUM', 'SET',
            // Date/Time
            'DATE', 'TIME', 'DATETIME', 'TIMESTAMP', 'YEAR',
            // Other
            'JSON',
        ];
    }

    public function columnTypesWithLength(): array
    {
        return ['CHAR', 'VARCHAR', 'BINARY', 'VARBINARY', 'BIT', 'DECIMAL', 'ENUM', 'SET'];
    }

    public function defaultColumnType(): string
    {
        return 'VARCHAR(255)';
    }

    public function referentialActions(): array
    {
        return ['RESTRICT', 'CASCADE', 'SET NULL', 'NO ACTION'];
    }

    public function textColumnTypePatterns(): array
    {
        return ['char', 'text', 'enum', 'set'];
    }

    public function welcomeQuery(): string
    {
        return 'SELECT NOW() AS now, VERSION() AS version;';
    }

    public function structureQueryTemplate(): string
    {
        return 'SHOW COLUMNS FROM `{db}`.`{table}`;';
    }

    public function listTablesQuery(): string
    {
        return 'SHOW TABLES IN `{db}`;';
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
            $this->pinnedDatabaseName = $db;
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
        $this->pinnedDatabaseName = null;
    }

    public function listDatabases(): array
    {
        if ($this->pinnedDatabaseName !== null) {
            // If we're already connected to a specific database, just return it.
            // This can happen when the admin pins a database in server config.
            return [$this->pinnedDatabaseName];
        }

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
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot list tables for $database");
        }

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
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot browse table in $database");
        }

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
            $extra = $c['EXTRA'] ?? '';
            $columns[] = [
                'name'          => $c['COLUMN_NAME'],
                'type'          => $c['COLUMN_TYPE'],
                'nullable'      => $c['IS_NULLABLE'] === 'YES',
                'key'           => self::normalizeKeyType($c['COLUMN_KEY'] ?? ''),
                'default'       => $c['COLUMN_DEFAULT'],
                'extra'         => $extra,
                'autoIncrement' => str_contains(strtolower($extra), 'auto_increment'),
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
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot run query in $database");
        }

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
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot insert row in $database");
        }

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
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot update row in $database");
        }

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
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot delete row in $database");
        }

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

    public function describeTable(string $database, string $table): array
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot describe table in $database");
        }

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
            $extra = $c['EXTRA'] ?? '';
            $columns[] = [
                'name'          => $c['COLUMN_NAME'],
                'type'          => $c['COLUMN_TYPE'],
                'nullable'      => $c['IS_NULLABLE'] === 'YES',
                'key'           => self::normalizeKeyType($c['COLUMN_KEY'] ?? ''),
                'default'       => $c['COLUMN_DEFAULT'],
                'extra'         => $extra,
                'comment'       => $c['COLUMN_COMMENT'] ?? '',
                'autoIncrement' => str_contains(strtolower($extra), 'auto_increment'),
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

    public function sampleTable(string $database, string $table, int $limit): array
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot sample table in $database");
        }

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
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot search schema in $database");
        }

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

    public function getDatabaseInfo(string $database): array
    {
        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            'SELECT DEFAULT_CHARACTER_SET_NAME AS charset, DEFAULT_COLLATION_NAME AS collation ' .
            'FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = :db'
        );
        $stmt->execute([':db' => $database]);
        $row = $stmt->fetch();
        return [
            'name'      => $database,
            'charset'   => $row['charset']   ?? null,
            'collation' => $row['collation'] ?? null,
        ];
    }

    public function getTableInfo(string $database, string $table): array
    {
        $this->ensureConnected();
        // To get accurate collation/engine/auto_increment info, we need to set disable the metadata cache,
        // otherwise if the table was altered recently we might get stale info back.
        $this->pdo->exec("SET SESSION information_schema_stats_expiry = 0");
        $stmt = $this->pdo->prepare(
            "SELECT TABLE_COLLATION, ENGINE, AUTO_INCREMENT
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND TABLE_TYPE = 'BASE TABLE'"
        );
        $stmt->execute([':db' => $database, ':tbl' => $table]);
        $row = $stmt->fetch();
        return [
            'name'          => $table,
            'collation'     => $row ? ($row['TABLE_COLLATION'] ?: null) : null,
            'engine'        => $row ? ($row['ENGINE'] ?: null) : null,
            'autoIncrement' => ($row && $row['AUTO_INCREMENT'] !== null) ? (int)$row['AUTO_INCREMENT'] : null,
        ];
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

    /** Allow only characters valid in a MySQL collation name. */
    private function sanitizeCollationName(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            throw new RuntimeException("Invalid collation name: $name");
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
     * Normalise a MySQL COLUMN_KEY value to the engine-neutral form used
     * throughout the API.
     */
    private static function normalizeKeyType(string $key): ?string
    {
        return match ($key) {
            'PRI'   => 'primary',
            'UNI'   => 'unique',
            'MUL'   => 'index',
            default => null,
        };
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
