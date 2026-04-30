<?php declare(strict_types=1);

namespace Quermy\Drivers;

use PDO;
use PDOException;
use Quermy\Drivers\Capabilities\ProvidesColumnTypes;
use Quermy\Drivers\Capabilities\ProvidesListTablesQuery;
use Quermy\Drivers\Capabilities\ProvidesStructureQueryTemplate;
use Quermy\Drivers\Capabilities\ProvidesWelcomeQuery;
use Quermy\Drivers\Capabilities\SupportsAddColumn;
use Quermy\Drivers\Capabilities\SupportsAlterDatabaseCollation;
use Quermy\Drivers\Capabilities\SupportsAutoIncrement;
use Quermy\Drivers\Capabilities\SupportsColumnAfter;
use Quermy\Drivers\Capabilities\SupportsDropColumn;
use Quermy\Drivers\Capabilities\SupportsDropDatabase;
use Quermy\Drivers\Capabilities\SupportsEventManagement;
use Quermy\Drivers\Capabilities\SupportsExplain;
use Quermy\Drivers\Capabilities\SupportsForeignKeyManagement;
use Quermy\Drivers\Capabilities\SupportsForeignKeys;
use Quermy\Drivers\Capabilities\SupportsFunctionManagement;
use Quermy\Drivers\Capabilities\SupportsGetCreateTable;
use Quermy\Drivers\Capabilities\SupportsIndexManagement;
use Quermy\Drivers\Capabilities\SupportsModifyColumn;
use Quermy\Drivers\Capabilities\SupportsProcedureManagement;
use Quermy\Drivers\Capabilities\SupportsRenameDatabase;
use Quermy\Drivers\Capabilities\SupportsReorderColumn;
use Quermy\Drivers\Capabilities\SupportsViewManagement;
use RuntimeException;

class MySQLDriver implements
    DriverInterface,
    SupportsAddColumn,
    SupportsModifyColumn,
    SupportsDropColumn,
    SupportsColumnAfter,
    SupportsAutoIncrement,
    SupportsReorderColumn,
    SupportsIndexManagement,
    SupportsForeignKeys,
    SupportsForeignKeyManagement,
    SupportsGetCreateTable,
    SupportsExplain,
    SupportsRenameDatabase,
    SupportsDropDatabase,
    SupportsAlterDatabaseCollation,
    SupportsViewManagement,
    SupportsProcedureManagement,
    SupportsFunctionManagement,
    SupportsEventManagement,
    ProvidesColumnTypes,
    ProvidesWelcomeQuery,
    ProvidesStructureQueryTemplate,
    ProvidesListTablesQuery
{
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
            'DECIMAL(10,2)', 'FLOAT', 'DOUBLE', 'BIT(1)',
            // String
            'CHAR(1)', 'VARCHAR(255)', 'TINYTEXT', 'TEXT',
            'MEDIUMTEXT', 'LONGTEXT',
            'BINARY(1)', 'VARBINARY(255)',
            'TINYBLOB', 'BLOB', 'MEDIUMBLOB', 'LONGBLOB',
            "ENUM('a','b')", "SET('a','b')",
            // Date/Time
            'DATE', 'TIME', 'DATETIME', 'TIMESTAMP', 'YEAR',
            // Other
            'JSON',
        ];
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

    public function listViews(string $database): array
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot list views for $database");
        }

        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT TABLE_NAME
             FROM information_schema.VIEWS
             WHERE TABLE_SCHEMA = :db
             ORDER BY TABLE_NAME"
        );
        $stmt->execute([':db' => $database]);
        return array_map(static fn($r) => $r['TABLE_NAME'], $stmt->fetchAll());
    }

    public function getViewDefinition(string $database, string $view): string
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot read view in $database");
        }

        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT VIEW_DEFINITION
             FROM information_schema.VIEWS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :view"
        );
        $stmt->execute([':db' => $database, ':view' => $view]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("View not found: $database.$view");
        }
        return trim((string)($row['VIEW_DEFINITION'] ?? ''));
    }

    public function upsertView(string $database, string $view, string $definition): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot save view in $database");
        }

        $this->ensureConnected();
        $database = $this->validateIdent($database);
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

        $qDb = $this->quoteIdent($database);
        $qView = $this->quoteIdent($name);

        // MySQL resolves unqualified identifiers in the view body against the
        // active catalog. Set it explicitly so CREATE VIEW works even when the
        // connection itself was opened without dbname.
        $this->pdo->exec("USE $qDb");

        $this->pdo->exec("CREATE OR REPLACE VIEW $qDb.$qView AS\n$body");
    }

    public function dropView(string $database, string $view): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot drop view in $database");
        }

        $this->ensureConnected();
        $name  = $this->validateIdent($view);
        $qDb   = $this->quoteIdent($database);
        $qView = $this->quoteIdent($name);
        $this->pdo->exec("DROP VIEW IF EXISTS $qDb.$qView");
    }

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

    public function listEvents(string $database): array
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot list events for $database");
        }

        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT EVENT_NAME
             FROM information_schema.EVENTS
             WHERE EVENT_SCHEMA = :db
             ORDER BY EVENT_NAME"
        );
        $stmt->execute([':db' => $database]);
        return array_map(static fn($r) => $r['EVENT_NAME'], $stmt->fetchAll());
    }

    public function getEventDefinition(string $database, string $event): string
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot read event in $database");
        }

        $this->ensureConnected();
        $qDb    = $this->quoteIdent($this->validateIdent($database));
        $qEvent = $this->quoteIdent($event);
        $stmt   = $this->pdo->query("SHOW CREATE EVENT $qDb.$qEvent");
        $row    = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Event not found: $database.$event");
        }
        return trim((string)($row['Create Event'] ?? ''));
    }

    public function upsertEvent(string $database, string $event, string $definition): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot save event in $database");
        }

        $this->ensureConnected();
        $name = $this->validateIdent($event);
        $body = trim($definition);
        if ($body === '') {
            throw new RuntimeException('Event definition is empty');
        }
        if (!preg_match('/^\s*CREATE\b/i', $body)) {
            throw new RuntimeException('Event definition must start with CREATE EVENT.');
        }

        $qDb    = $this->quoteIdent($database);
        $qEvent = $this->quoteIdent($name);
        // MySQL resolves unqualified identifiers in the view body against the
        // active catalog. Set it explicitly so CREATE VIEW works even when the
        // connection itself was opened without dbname.
        $this->pdo->exec("USE $qDb");
        $this->pdo->exec("DROP EVENT IF EXISTS $qDb.$qEvent");
        $this->pdo->exec($body);
    }

    public function dropEvent(string $database, string $event): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot drop event in $database");
        }

        $this->ensureConnected();
        $name   = $this->validateIdent($event);
        $qDb    = $this->quoteIdent($database);
        $qEvent = $this->quoteIdent($name);
        $this->pdo->exec("DROP EVENT IF EXISTS $qDb.$qEvent");
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

    public function addColumn(string $database, string $table, array $definition): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot add column in $database");
        }

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
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot modify column in $database");
        }

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
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot drop column in $database");
        }

        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($columnName);
        $this->pdo->exec("ALTER TABLE $qDb.$qTbl DROP COLUMN $qCol");
    }

    public function reorderColumn(string $database, string $table, string $columnName, ?string $afterColumn): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot reorder column in $database");
        }

        $this->ensureConnected();

        // Fetch the current column definition so we can re-issue it with a position clause.
        $stmt = $this->pdo->prepare(
            "SELECT COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND COLUMN_NAME = :col"
        );
        $stmt->execute([':db' => $database, ':tbl' => $table, ':col' => $columnName]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new RuntimeException("Column not found: $columnName");
        }

        $qDb    = $this->quoteIdent($database);
        $qTbl   = $this->quoteIdent($table);
        $qCol   = $this->quoteIdent($columnName);
        $type   = $this->sanitizeColumnType($row['COLUMN_TYPE']);
        $null   = $row['IS_NULLABLE'] === 'YES' ? '' : ' NOT NULL';
        $extra  = strtolower($row['EXTRA'] ?? '');
        $ai     = str_contains($extra, 'auto_increment') ? ' AUTO_INCREMENT' : '';
        $def    = ($ai === '')
                    ? (isset($row['COLUMN_DEFAULT']) && $row['COLUMN_DEFAULT'] !== null
                        ? ' DEFAULT ' . $this->pdo->quote((string)$row['COLUMN_DEFAULT'])
                        : ($row['IS_NULLABLE'] === 'YES' ? ' DEFAULT NULL' : ''))
                    : '';
        $position = $afterColumn !== null
                        ? ' AFTER ' . $this->quoteIdent($afterColumn)
                        : ' FIRST';

        $this->pdo->exec("ALTER TABLE $qDb.$qTbl MODIFY COLUMN $qCol $type$null$def$ai$position");
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

    public function getForeignKeys(string $database, string $table): array
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot get foreign keys for $database");
        }

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

    public function getCreateTable(string $database, string $table): string
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot get create table for $database");
        }

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
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot explain query for $database");
        }

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

    public function createIndex(string $database, string $table, array $definition): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot create index in $database");
        }

        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);
        $cols = implode(', ', array_map([$this, 'quoteIdent'], $definition['columns']));

        if (!empty($definition['primary'])) {
            $this->pdo->exec("ALTER TABLE $qDb.$qTbl ADD PRIMARY KEY ($cols)");
        } else {
            $this->validateIdent($definition['name']);
            $qIdx = $this->quoteIdent($definition['name']);
            $uniq = !empty($definition['unique']) ? 'UNIQUE ' : '';
            $this->pdo->exec("CREATE {$uniq}INDEX $qIdx ON $qDb.$qTbl ($cols)");
        }
    }

    public function dropIndex(string $database, string $table, string $indexName, bool $isPrimary): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot drop index in $database");
        }

        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qTbl = $this->quoteIdent($table);

        if ($isPrimary) {
            $this->pdo->exec("ALTER TABLE $qDb.$qTbl DROP PRIMARY KEY");
        } else {
            $this->validateIdent($indexName);
            $qIdx = $this->quoteIdent($indexName);
            $this->pdo->exec("DROP INDEX $qIdx ON $qDb.$qTbl");
        }
    }

    public function createForeignKey(string $database, string $table, array $definition): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot create foreign key in $database");
        }

        $this->ensureConnected();
        $this->validateIdent($definition['name']);
        $this->validateIdent($definition['referencedTable']);
        $qDb     = $this->quoteIdent($database);
        $qTbl    = $this->quoteIdent($table);
        $qCons   = $this->quoteIdent($definition['name']);
        $qRef    = $this->quoteIdent($definition['referencedTable']);
        $cols    = implode(', ', array_map([$this, 'quoteIdent'], $definition['columns']));
        $refCols = implode(', ', array_map([$this, 'quoteIdent'], $definition['referencedColumns']));
        $onUpdate = $this->sanitizeReferentialAction($definition['onUpdate'] ?? 'RESTRICT');
        $onDelete = $this->sanitizeReferentialAction($definition['onDelete'] ?? 'RESTRICT');

        $this->pdo->exec(
            "ALTER TABLE $qDb.$qTbl ADD CONSTRAINT $qCons "
            . "FOREIGN KEY ($cols) REFERENCES $qDb.$qRef ($refCols) "
            . "ON UPDATE $onUpdate ON DELETE $onDelete"
        );
    }

    public function dropForeignKey(string $database, string $table, string $constraintName): void
    {
        if ($this->pinnedDatabaseName !== null && $this->pinnedDatabaseName !== $database) {
            throw new RuntimeException("Connected to {$this->pinnedDatabaseName}, cannot drop foreign key in $database");
        }

        $this->ensureConnected();
        $this->validateIdent($constraintName);
        $qDb   = $this->quoteIdent($database);
        $qTbl  = $this->quoteIdent($table);
        $qCons = $this->quoteIdent($constraintName);
        $this->pdo->exec("ALTER TABLE $qDb.$qTbl DROP FOREIGN KEY $qCons");
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

    public function renameDatabase(string $database, string $newName): void
    {
        $this->ensureConnected();
        $db  = $this->validateIdent($database);
        $ndb = $this->validateIdent($newName);

        // Capture charset/collation from the source schema
        $info    = $this->getDatabaseInfo($db);
        $charset = $this->validateIdent($info['charset'] ?? 'utf8mb4');
        $coll    = $this->sanitizeCollationName($info['collation'] ?? 'utf8mb4_unicode_ci');

        // Create the target database
        $this->pdo->exec("CREATE DATABASE `$ndb` CHARACTER SET `$charset` COLLATE `$coll`");

        // Move every base table (MySQL has no single-step RENAME DATABASE)
        $stmt = $this->pdo->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES " .
            "WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'"
        );
        $stmt->execute([':db' => $db]);
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table) {
            $t = $this->validateIdent($table);
            $this->pdo->exec("RENAME TABLE `$db`.`$t` TO `$ndb`.`$t`");
        }

        // Drop the (now-empty) source database
        $this->pdo->exec("DROP DATABASE `$db`");
    }

    public function alterDatabaseCollation(string $database, string $collation): void
    {
        $this->ensureConnected();
        $db   = $this->validateIdent($database);
        $coll = $this->sanitizeCollationName($collation);

        // Resolve the character set for this collation
        $stmt = $this->pdo->prepare(
            'SELECT CHARACTER_SET_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME = :coll'
        );
        $stmt->execute([':coll' => $coll]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("Unknown collation: $coll");
        }
        $charset = $this->validateIdent($row['CHARACTER_SET_NAME']);
        $this->pdo->exec("ALTER DATABASE `$db` CHARACTER SET `$charset` COLLATE `$coll`");
    }

    public function dropDatabase(string $database): void
    {
        $this->ensureConnected();
        $db = $this->validateIdent($database);
        $this->pdo->exec("DROP DATABASE `$db`");
    }

    public function listDatabaseCollations(string $database): array
    {
        $this->ensureConnected();

        $all = $this->pdo->query('SELECT COLLATION_NAME FROM information_schema.COLLATIONS ORDER BY COLLATION_NAME');
        return array_map(static fn($r) => $r['COLLATION_NAME'], $all->fetchAll());
    }

    /*
     * Private helpers
     */

    private function sanitizeReferentialAction(string $action): string
    {
        $valid  = ['CASCADE', 'RESTRICT', 'SET NULL', 'SET DEFAULT', 'NO ACTION'];
        $action = strtoupper(trim($action));
        if (!in_array($action, $valid, true)) {
            throw new RuntimeException("Invalid referential action: $action");
        }
        return $action;
    }

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
