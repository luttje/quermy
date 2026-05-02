<?php declare(strict_types=1);

namespace Quermy\Drivers;

use PDO;
use PDOException;
use Quermy\Drivers\Capabilities\ProvidesColumnTypes;
use Quermy\Drivers\Capabilities\ProvidesColumnTypesWithLength;
use Quermy\Drivers\Capabilities\ProvidesDefaultColumnType;
use Quermy\Drivers\Capabilities\ProvidesListTablesQuery;
use Quermy\Drivers\Capabilities\ProvidesReferentialActions;
use Quermy\Drivers\Capabilities\ProvidesStructureQueryTemplate;
use Quermy\Drivers\Capabilities\ProvidesTextColumnTypePatterns;
use Quermy\Drivers\Capabilities\ProvidesWelcomeQuery;
use Quermy\Drivers\Capabilities\SQLServer\SupportAddColumn;
use Quermy\Drivers\Capabilities\SQLServer\SupportAlterDatabaseCollation;
use Quermy\Drivers\Capabilities\SQLServer\SupportCreateTable;
use Quermy\Drivers\Capabilities\SQLServer\SupportDropColumn;
use Quermy\Drivers\Capabilities\SQLServer\SupportDropDatabase;
use Quermy\Drivers\Capabilities\SQLServer\SupportDropTable;
use Quermy\Drivers\Capabilities\SQLServer\SupportExplain;
use Quermy\Drivers\Capabilities\SQLServer\SupportForeignKeyManagement;
use Quermy\Drivers\Capabilities\SQLServer\SupportForeignKeys;
use Quermy\Drivers\Capabilities\SQLServer\SupportFunctionManagement;
use Quermy\Drivers\Capabilities\SQLServer\SupportGetCreateTable;
use Quermy\Drivers\Capabilities\SQLServer\SupportIndexManagement;
use Quermy\Drivers\Capabilities\SQLServer\SupportModifyColumn;
use Quermy\Drivers\Capabilities\SQLServer\SupportProcedureManagement;
use Quermy\Drivers\Capabilities\SQLServer\SupportRenameDatabase;
use Quermy\Drivers\Capabilities\SQLServer\SupportTruncateTable;
use Quermy\Drivers\Capabilities\SQLServer\SupportViewManagement;
use Quermy\Drivers\Capabilities\SupportsAddColumn;
use Quermy\Drivers\Capabilities\SupportsAlterDatabaseCollation;
use Quermy\Drivers\Capabilities\SupportsAutoIncrement;
use Quermy\Drivers\Capabilities\SupportsCreateTable;
use Quermy\Drivers\Capabilities\SupportsDropColumn;
use Quermy\Drivers\Capabilities\SupportsDropDatabase;
use Quermy\Drivers\Capabilities\SupportsDropTable;
use Quermy\Drivers\Capabilities\SupportsExplain;
use Quermy\Drivers\Capabilities\SupportsForeignKeyManagement;
use Quermy\Drivers\Capabilities\SupportsForeignKeys;
use Quermy\Drivers\Capabilities\SupportsFunctionManagement;
use Quermy\Drivers\Capabilities\SupportsGetCreateTable;
use Quermy\Drivers\Capabilities\SupportsIndexManagement;
use Quermy\Drivers\Capabilities\SupportsModifyColumn;
use Quermy\Drivers\Capabilities\SupportsProcedureManagement;
use Quermy\Drivers\Capabilities\SupportsRenameDatabase;
use Quermy\Drivers\Capabilities\SupportsTruncateTable;
use Quermy\Drivers\Capabilities\SupportsViewManagement;
use RuntimeException;

/**
 * Microsoft SQL Server driver.
 *
 * Uses `pdo_sqlsrv` (Windows / cross-platform Microsoft driver).
 * Falls back gracefully with a clear error message if the extension is not
 * available so the driver can still be registered in DriverFactory.
 *
 * Identifiers are quoted with square brackets [name].
 * The "database" config field maps to the SQL Server database (catalog).
 */
class SQLServerDriver implements
    DriverInterface,
    ProvidesColumnTypes,
    ProvidesColumnTypesWithLength,
    ProvidesDefaultColumnType,
    ProvidesListTablesQuery,
    ProvidesReferentialActions,
    ProvidesStructureQueryTemplate,
    ProvidesTextColumnTypePatterns,
    ProvidesWelcomeQuery,
    SupportsAddColumn,
    SupportsAlterDatabaseCollation,
    SupportsAutoIncrement,
    SupportsCreateTable,
    SupportsDropColumn,
    SupportsDropDatabase,
    SupportsDropTable,
    SupportsExplain,
    SupportsForeignKeyManagement,
    SupportsForeignKeys,
    SupportsFunctionManagement,
    SupportsGetCreateTable,
    SupportsIndexManagement,
    SupportsModifyColumn,
    SupportsProcedureManagement,
    SupportsRenameDatabase,
    SupportsTruncateTable,
    SupportsViewManagement
{
    use SupportAddColumn,
        SupportAlterDatabaseCollation,
        SupportCreateTable,
        SupportDropColumn,
        SupportDropDatabase,
        SupportDropTable,
        SupportExplain,
        SupportForeignKeyManagement,
        SupportForeignKeys,
        SupportFunctionManagement,
        SupportGetCreateTable,
        SupportIndexManagement,
        SupportModifyColumn,
        SupportProcedureManagement,
        SupportRenameDatabase,
        SupportTruncateTable,
        SupportViewManagement;

    private ?PDO $pdo = null;

    public static function engineId(): string
    {
        return 'sqlserver';
    }

    public static function engineMeta(): array
    {
        return [
            'id'              => 'sqlserver',
            'label'           => 'SQL Server',
            'defaultPort'     => 1433,
            'defaultUsername' => 'sa',
            'connectionType'  => 'tcp',
            'identifierOpen'  => '[',
            'identifierClose' => ']',
        ];
    }

    public function columnTypes(): array
    {
        return [
            // Exact numerics
            'TINYINT', 'SMALLINT', 'INT', 'BIGINT',
            'DECIMAL', 'NUMERIC', 'MONEY', 'SMALLMONEY',
            // Approximate numerics
            'REAL', 'FLOAT',
            // Date and time
            'DATE', 'TIME', 'DATETIME', 'DATETIME2', 'SMALLDATETIME', 'DATETIMEOFFSET',
            // Character strings
            'CHAR', 'VARCHAR', 'TEXT',
            'NCHAR', 'NVARCHAR', 'NTEXT',
            // Binary
            'BINARY', 'VARBINARY', 'IMAGE',
            // Other
            'BIT', 'UNIQUEIDENTIFIER', 'XML', 'JSON',
            'GEOMETRY', 'GEOGRAPHY',
        ];
    }

    public function columnTypesWithLength(): array
    {
        // VARCHAR/NVARCHAR accept MAX as a special keyword in addition to numeric sizes.
        return ['CHAR', 'VARCHAR', 'NCHAR', 'NVARCHAR', 'BINARY', 'VARBINARY', 'DECIMAL', 'NUMERIC'];
    }

    public function defaultColumnType(): string
    {
        return 'NVARCHAR(255)';
    }

    public function referentialActions(): array
    {
        return ['CASCADE', 'NO ACTION', 'SET NULL', 'SET DEFAULT'];
    }

    public function textColumnTypePatterns(): array
    {
        return ['char', 'text', 'nvar'];
    }

    public function welcomeQuery(): string
    {
        return 'SELECT GETDATE() AS now, @@VERSION AS version;';
    }

    public function structureQueryTemplate(): string
    {
        return "SELECT column_name, data_type, is_nullable, column_default, ordinal_position\nFROM INFORMATION_SCHEMA.COLUMNS\nWHERE TABLE_NAME = '{table}'\nORDER BY ordinal_position;";
    }

    public function listTablesQuery(): string
    {
        return "SELECT name\nFROM sys.tables\nORDER BY name;";
    }

    public function connect(array $config): void
    {
        if (!extension_loaded('pdo_sqlsrv')) {
            throw new RuntimeException(
                'The pdo_sqlsrv PHP extension is required to connect to SQL Server. '
                . 'Install it from https://docs.microsoft.com/en-us/sql/connect/php/download-drivers-php-sql-server'
            );
        }

        $host = $config['host'] ?? '127.0.0.1';
        $port = (int)($config['port'] ?? 1433);
        $user = $config['username'] ?? '';
        $pass = $config['password'] ?? '';
        $db   = $config['database'] ?? '';

        $server = $port !== 1433 ? "$host,$port" : $host;
        $dsnParts = ["Server=$server"];
        if ($db !== '') {
            $dsnParts[] = "Database=$db";
        }
        $dsn = 'sqlsrv:' . implode(';', $dsnParts);

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // SQL Server does not support ATTR_EMULATE_PREPARES; omit it.
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Could not connect: ' . $e->getMessage(), 0, $e);
        }
    }

    public function disconnect(): void
    {
        $this->pdo = null;
    }

    public function listDatabases(): array
    {
        $this->ensureConnected();
        $stmt = $this->pdo->query(
            "SELECT name FROM sys.databases
             WHERE database_id > 4 AND state_desc = 'ONLINE'
             ORDER BY name"
        );
        return array_map(static fn($r) => $r['name'], $stmt->fetchAll());
    }

    public function listTables(string $database): array
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($database));
        }
        $stmt = $this->pdo->query(
            "SELECT t.name AS table_name,
                    p.rows AS row_count,
                    (SUM(a.total_pages) * 8 * 1024) AS total_bytes
             FROM sys.tables t
             JOIN sys.indexes i ON i.object_id = t.object_id AND i.index_id IN (0, 1)
             JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id = i.index_id
             JOIN sys.allocation_units a ON a.container_id = p.partition_id
             WHERE t.is_ms_shipped = 0 AND SCHEMA_NAME(t.schema_id) = 'dbo'
             GROUP BY t.name, p.rows
             ORDER BY t.name"
        );
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'name' => $row['table_name'],
                'rows' => (int)$row['row_count'],
                'size' => (int)$row['total_bytes'],
            ];
        }
        return $out;
    }

    public function browseTable(string $database, string $table, int $limit, int $offset): array
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $this->validateIdent($table);

        // Column metadata
        $cstmt = $this->pdo->prepare(
            "SELECT c.COLUMN_NAME, c.DATA_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT,
                    COLUMNPROPERTY(OBJECT_ID(:tbl), c.COLUMN_NAME, 'IsIdentity') AS is_identity,
                    CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN 1 ELSE 0 END AS is_pk
             FROM INFORMATION_SCHEMA.COLUMNS c
             LEFT JOIN (
                 SELECT kcu.COLUMN_NAME
                 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                 JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                   ON kcu.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                 WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY' AND tc.TABLE_NAME = :tbl2
             ) pk ON pk.COLUMN_NAME = c.COLUMN_NAME
             WHERE c.TABLE_NAME = :tbl3
             ORDER BY c.ORDINAL_POSITION"
        );
        $cstmt->execute([':tbl' => $table, ':tbl2' => $table, ':tbl3' => $table]);
        $columns = [];
        foreach ($cstmt->fetchAll() as $c) {
            $isIdentity = (bool)(int)$c['is_identity'];
            $isPk       = (bool)(int)$c['is_pk'];
            $columns[]  = [
                'name'          => $c['COLUMN_NAME'],
                'type'          => strtoupper($c['DATA_TYPE']),
                'nullable'      => $c['IS_NULLABLE'] === 'YES',
                'key'           => $isPk ? 'primary' : null,
                'default'       => $c['COLUMN_DEFAULT'],
                'extra'         => $isIdentity ? 'auto_increment' : '',
                'autoIncrement' => $isIdentity,
            ];
        }

        // Total
        $qTbl  = $this->quoteIdent($table);
        $total = (int)$this->pdo->query("SELECT COUNT(*) AS c FROM dbo.$qTbl")->fetch()['c'];

        // Paginated rows (SQL Server 2012+)
        $limit  = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $rstmt  = $this->pdo->query(
            "SELECT * FROM dbo.$qTbl
             ORDER BY (SELECT NULL)
             OFFSET $offset ROWS FETCH NEXT $limit ROWS ONLY"
        );
        $rows = $rstmt->fetchAll();

        return ['columns' => $columns, 'rows' => $rows, 'total' => $total];
    }

    public function runQuery(string $database, string $sql): array
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }

        $start    = microtime(true);
        $stmt     = $this->pdo->query($sql);
        $duration = (microtime(true) - $start) * 1000.0;

        $isSelect = $stmt->columnCount() > 0;
        $rows     = $isSelect ? $stmt->fetchAll() : [];

        $columns = [];
        if ($isSelect) {
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta      = $stmt->getColumnMeta($i) ?: [];
                $columns[] = ['name' => $meta['name'] ?? "col_$i", 'type' => $meta['native_type'] ?? 'unknown'];
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
        if (empty($values)) throw new RuntimeException('No values provided for insert');
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $qTbl = $this->quoteIdent($table);
        $cols = implode(', ', array_map([$this, 'quoteIdent'], array_keys($values)));
        $phs  = implode(', ', array_fill(0, count($values), '?'));
        $stmt = $this->pdo->prepare("INSERT INTO dbo.$qTbl ($cols) VALUES ($phs)");
        $stmt->execute(array_values($values));
        $insertId = (int)$this->pdo->query('SELECT SCOPE_IDENTITY() AS id')->fetchColumn();
        return ['affected' => $stmt->rowCount(), 'insertId' => $insertId];
    }

    public function updateRow(string $database, string $table, array $where, array $values): array
    {
        $this->ensureConnected();
        if (empty($where))  throw new RuntimeException('No WHERE conditions provided');
        if (empty($values)) throw new RuntimeException('No values to update');
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $qTbl         = $this->quoteIdent($table);
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

        $stmt = $this->pdo->prepare(
            "UPDATE dbo.$qTbl SET " . implode(', ', $setClauses) . ' WHERE ' . implode(' AND ', $whereClauses)
        );
        $stmt->execute($params);
        return ['affected' => $stmt->rowCount()];
    }

    public function deleteRow(string $database, string $table, array $where): array
    {
        $this->ensureConnected();
        if (empty($where)) throw new RuntimeException('No WHERE conditions provided');
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $qTbl         = $this->quoteIdent($table);
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

        $stmt = $this->pdo->prepare("DELETE FROM dbo.$qTbl WHERE " . implode(' AND ', $whereClauses));
        $stmt->execute($params);
        return ['affected' => $stmt->rowCount()];
    }

    public function describeTable(string $database, string $table): array
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }

        $cstmt = $this->pdo->prepare(
            "SELECT c.COLUMN_NAME, c.DATA_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT,
                    COLUMNPROPERTY(OBJECT_ID(:tbl), c.COLUMN_NAME, 'IsIdentity') AS is_identity,
                    CASE WHEN pk.COLUMN_NAME IS NOT NULL THEN 1 ELSE 0 END AS is_pk,
                    ISNULL(ep.value, '') AS comment
             FROM INFORMATION_SCHEMA.COLUMNS c
             LEFT JOIN (
                 SELECT kcu.COLUMN_NAME
                 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
                 JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
                   ON kcu.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
                 WHERE tc.CONSTRAINT_TYPE = 'PRIMARY KEY' AND tc.TABLE_NAME = :tbl2
             ) pk ON pk.COLUMN_NAME = c.COLUMN_NAME
             LEFT JOIN sys.extended_properties ep
               ON ep.major_id = OBJECT_ID(:tbl3)
              AND ep.minor_id = c.ORDINAL_POSITION
              AND ep.name = 'MS_Description'
             WHERE c.TABLE_NAME = :tbl4
             ORDER BY c.ORDINAL_POSITION"
        );
        $cstmt->execute([':tbl' => $table, ':tbl2' => $table, ':tbl3' => $table, ':tbl4' => $table]);
        $columns = [];
        foreach ($cstmt->fetchAll() as $c) {
            $isIdentity = (bool)(int)$c['is_identity'];
            $isPk       = (bool)(int)$c['is_pk'];
            $columns[]  = [
                'name'          => $c['COLUMN_NAME'],
                'type'          => strtoupper($c['DATA_TYPE']),
                'nullable'      => $c['IS_NULLABLE'] === 'YES',
                'key'           => $isPk ? 'primary' : null,
                'default'       => $c['COLUMN_DEFAULT'],
                'extra'         => $isIdentity ? 'auto_increment' : '',
                'comment'       => (string)$c['comment'],
                'autoIncrement' => $isIdentity,
            ];
        }

        if ($columns === []) {
            throw new RuntimeException("Table not found or not accessible: $table");
        }

        $istmt = $this->pdo->prepare(
            "SELECT i.name AS index_name, c.name AS column_name, i.is_unique
             FROM sys.indexes i
             JOIN sys.index_columns ic ON ic.object_id = i.object_id AND ic.index_id = i.index_id
             JOIN sys.columns c ON c.object_id = i.object_id AND c.column_id = ic.column_id
             WHERE OBJECT_NAME(i.object_id) = :tbl
             ORDER BY i.name, ic.key_ordinal"
        );
        $istmt->execute([':tbl' => $table]);
        $byIndex = [];
        foreach ($istmt->fetchAll() as $r) {
            $name = $r['index_name'];
            if (!isset($byIndex[$name])) {
                $byIndex[$name] = ['name' => $name, 'columns' => [], 'unique' => (bool)$r['is_unique']];
            }
            $byIndex[$name]['columns'][] = $r['column_name'];
        }

        $primaryKey = [];
        foreach ($columns as $c) {
            if ($c['key'] === 'primary') $primaryKey[] = $c['name'];
        }

        return ['columns' => $columns, 'primaryKey' => $primaryKey, 'indexes' => array_values($byIndex)];
    }

    public function sampleTable(string $database, string $table, int $limit): array
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $this->validateIdent($table);
        $limit = max(1, min(20, $limit));
        $qTbl  = $this->quoteIdent($table);

        $stmt  = $this->pdo->query("SELECT TOP $limit * FROM dbo.$qTbl");
        $rows  = $stmt->fetchAll();

        $columns = [];
        for ($i = 0; $i < $stmt->columnCount(); $i++) {
            $meta      = $stmt->getColumnMeta($i) ?: [];
            $columns[] = $meta['name'] ?? "col_$i";
        }

        return ['columns' => $columns, 'rows' => $rows, 'truncated' => count($rows) >= $limit];
    }

    public function searchSchema(string $database, string $term, string $scope): array
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $like    = '%' . $this->escapeLike($term) . '%';
        $tables  = [];
        $columns = [];

        if ($scope === 'tables' || $scope === 'all') {
            $stmt = $this->pdo->prepare(
                "SELECT TABLE_NAME,
                        ISNULL(CAST(ep.value AS NVARCHAR(MAX)), '') AS comment
                 FROM INFORMATION_SCHEMA.TABLES t
                 LEFT JOIN sys.extended_properties ep
                   ON ep.major_id = OBJECT_ID(TABLE_NAME)
                  AND ep.minor_id = 0
                  AND ep.name = 'MS_Description'
                 WHERE TABLE_TYPE = 'BASE TABLE' AND TABLE_SCHEMA = 'dbo'
                   AND TABLE_NAME LIKE :term ESCAPE '\\'
                 ORDER BY TABLE_NAME"
            );
            $stmt->execute([':term' => $like]);
            foreach ($stmt->fetchAll() as $r) {
                $tables[] = ['name' => $r['TABLE_NAME'], 'comment' => $r['comment']];
            }
        }

        if ($scope === 'columns' || $scope === 'all') {
            $stmt = $this->pdo->prepare(
                "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE,
                        ISNULL(CAST(ep.value AS NVARCHAR(MAX)), '') AS comment
                 FROM INFORMATION_SCHEMA.COLUMNS c
                 LEFT JOIN sys.extended_properties ep
                   ON ep.major_id = OBJECT_ID(c.TABLE_NAME)
                  AND ep.minor_id = c.ORDINAL_POSITION
                  AND ep.name = 'MS_Description'
                 WHERE TABLE_SCHEMA = 'dbo'
                   AND COLUMN_NAME LIKE :term ESCAPE '\\'
                 ORDER BY TABLE_NAME, ORDINAL_POSITION"
            );
            $stmt->execute([':term' => $like]);
            foreach ($stmt->fetchAll() as $r) {
                $columns[] = [
                    'table'   => $r['TABLE_NAME'],
                    'name'    => $r['COLUMN_NAME'],
                    'type'    => strtoupper($r['DATA_TYPE']),
                    'comment' => $r['comment'],
                ];
                if (count($columns) >= 200) break;
            }
        }

        return ['tables' => $tables, 'columns' => $columns];
    }

    public function getDatabaseInfo(string $database): array
    {
        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            'SELECT name, collation_name FROM sys.databases WHERE name = ?'
        );
        $stmt->execute([$database]);
        $row = $stmt->fetch();
        return [
            'name'      => $row['name']           ?? $database,
            'charset'   => null,
            'collation' => $row['collation_name'] ?? null,
        ];
    }

    /*
     * Private helpers
     */

    private function sanitizeReferentialAction(string $action): string
    {
        // SQL Server does not support RESTRICT.
        $valid  = ['CASCADE', 'NO ACTION', 'SET NULL', 'SET DEFAULT'];
        $action = strtoupper(trim($action));
        if ($action === 'RESTRICT') $action = 'NO ACTION'; // graceful alias
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

    private function quoteIdent(string $name): string
    {
        if ($name === '') throw new RuntimeException('Empty identifier');
        return '[' . str_replace(']', ']]', $name) . ']';
    }

    private function validateIdent(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_$]+$/', $name)) {
            throw new RuntimeException("Invalid identifier: $name");
        }
        return $name;
    }

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

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    private function extractViewBody(string $definition): string
    {
        $sql = trim($definition);
        if (preg_match('/\bAS\b(.*)$/is', $sql, $m)) {
            return trim($m[1]);
        }
        return $sql;
    }
}
