<?php declare(strict_types=1);

namespace Quermy\Drivers;

use PDO;
use PDOException;
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
class SQLServerDriver implements DriverInterface
{
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

    public function getCapabilities(): array
    {
        return [
            'columnTypes' => [
                // Exact numerics
                'TINYINT', 'SMALLINT', 'INT', 'BIGINT',
                'DECIMAL(10,2)', 'NUMERIC(10,2)', 'MONEY', 'SMALLMONEY',
                // Approximate numerics
                'REAL', 'FLOAT',
                // Date and time
                'DATE', 'TIME', 'DATETIME', 'DATETIME2', 'SMALLDATETIME', 'DATETIMEOFFSET',
                // Character strings
                'CHAR(1)', 'VARCHAR(255)', 'VARCHAR(MAX)', 'TEXT',
                'NCHAR(1)', 'NVARCHAR(255)', 'NVARCHAR(MAX)', 'NTEXT',
                // Binary
                'BINARY(1)', 'VARBINARY(255)', 'VARBINARY(MAX)', 'IMAGE',
                // Other
                'BIT', 'UNIQUEIDENTIFIER', 'XML', 'JSON',
                'GEOMETRY', 'GEOGRAPHY',
            ],
            'supportsAutoIncrement'  => true,   // IDENTITY(1,1)
            'supportsColumnAfter'    => false,
            'supportsModifyColumn'   => true,
            'supportsDropColumn'     => true,
            'supportsReorderColumn'  => false,
            'supportsGetCreateTable' => true,
            'supportsExplain'        => true,
            'supportsForeignKeys'    => true,
            'supportsIndexManagement'       => true,
            'supportsPrimaryKeyManagement'  => true,
            'supportsForeignKeyManagement'  => true,
            'supportsRenameDatabase'         => true,
            'supportsAlterDatabaseCollation' => true,
            'supportsDropDatabase'           => true,
            'supportsViewManagement'         => true,
            'supportsProcedureManagement'    => true,
            'supportsFunctionManagement'     => true,
            'supportsEventManagement'        => false,
            'welcomeQuery'           => 'SELECT GETDATE() AS now, @@VERSION AS version;',
            'structureQueryTemplate' => "SELECT column_name, data_type, is_nullable, column_default, ordinal_position\nFROM INFORMATION_SCHEMA.COLUMNS\nWHERE TABLE_NAME = '{table}'\nORDER BY ordinal_position;",
            'identifierOpen'         => '[',
            'identifierClose'        => ']',
        ];
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

    public function listViews(string $database): array
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $stmt = $this->pdo->query(
            "SELECT v.name
             FROM sys.views v
             JOIN sys.schemas s ON s.schema_id = v.schema_id
             WHERE s.name = 'dbo'
             ORDER BY v.name"
        );
        return array_map(static fn($r) => $r['name'], $stmt->fetchAll());
    }

    public function getViewDefinition(string $database, string $view): string
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $stmt = $this->pdo->prepare(
            "SELECT m.definition
             FROM sys.views v
             JOIN sys.schemas s ON s.schema_id = v.schema_id
             JOIN sys.sql_modules m ON m.object_id = v.object_id
             WHERE s.name = 'dbo' AND v.name = :view"
        );
        $stmt->execute([':view' => $view]);
        $row = $stmt->fetch();
        if (!$row || !isset($row['definition'])) {
            throw new RuntimeException("View not found: $view");
        }
        return $this->extractViewBody((string)$row['definition']);
    }

    public function upsertView(string $database, string $view, string $definition): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
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
        $qView     = $this->quoteIdent($name);
        $viewName  = 'dbo.' . $name;
        $viewLit   = $this->pdo->quote($viewName);

        // CREATE VIEW must be the first statement in a batch, so we do
        // existence check/drop and create in separate exec() calls.
        $this->pdo->exec("IF OBJECT_ID($viewLit, 'V') IS NOT NULL DROP VIEW dbo.$qView");
        $this->pdo->exec("CREATE VIEW dbo.$qView AS\n$body");
    }

    public function dropView(string $database, string $view): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $name      = $this->validateIdent($view);
        $qView     = $this->quoteIdent($name);
        $viewName  = 'dbo.' . $name;
        $viewLit   = $this->pdo->quote($viewName);
        $this->pdo->exec("IF OBJECT_ID($viewLit, 'V') IS NOT NULL DROP VIEW dbo.$qView");
    }

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

    public function listFunctions(string $database): array
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $stmt = $this->pdo->query(
            "SELECT o.name
             FROM sys.objects o
             JOIN sys.schemas s ON s.schema_id = o.schema_id
             WHERE s.name = 'dbo' AND o.type IN ('FN', 'IF', 'TF')
             ORDER BY o.name"
        );
        return array_map(static fn($r) => $r['name'], $stmt->fetchAll());
    }

    public function getFunctionDefinition(string $database, string $function): string
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $stmt = $this->pdo->prepare(
            "SELECT m.definition
             FROM sys.objects o
             JOIN sys.schemas s ON s.schema_id = o.schema_id
             JOIN sys.sql_modules m ON m.object_id = o.object_id
             WHERE s.name = 'dbo' AND o.name = :name AND o.type IN ('FN', 'IF', 'TF')"
        );
        $stmt->execute([':name' => $function]);
        $row = $stmt->fetch();
        if (!$row || !isset($row['definition'])) {
            throw new RuntimeException("Function not found: $function");
        }
        return trim((string)$row['definition']);
    }

    public function upsertFunction(string $database, string $function, string $definition): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $name    = $this->validateIdent($function);
        $body    = trim($definition);
        if ($body === '') {
            throw new RuntimeException('Function definition is empty');
        }
        if (!preg_match('/^\s*CREATE\b/i', $body)) {
            throw new RuntimeException('Function definition must start with CREATE FUNCTION.');
        }
        $qFunc   = $this->quoteIdent($name);
        $funcLit = $this->pdo->quote('dbo.' . $name);
        // Drop scalar, inline-TVF, and multi-statement TVF types.
        $this->pdo->exec(
            "IF OBJECT_ID($funcLit, 'FN') IS NOT NULL OR OBJECT_ID($funcLit, 'IF') IS NOT NULL OR OBJECT_ID($funcLit, 'TF') IS NOT NULL DROP FUNCTION dbo.$qFunc"
        );
        $this->pdo->exec($body);
    }

    public function dropFunction(string $database, string $function): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $name    = $this->validateIdent($function);
        $qFunc   = $this->quoteIdent($name);
        $funcLit = $this->pdo->quote('dbo.' . $name);
        $this->pdo->exec(
            "IF OBJECT_ID($funcLit, 'FN') IS NOT NULL OR OBJECT_ID($funcLit, 'IF') IS NOT NULL OR OBJECT_ID($funcLit, 'TF') IS NOT NULL DROP FUNCTION dbo.$qFunc"
        );
    }

    public function listEvents(string $database): array
    {
        throw new RuntimeException('SQL Server does not support scheduled events.');
    }

    public function getEventDefinition(string $database, string $event): string
    {
        throw new RuntimeException('SQL Server does not support scheduled events.');
    }

    public function upsertEvent(string $database, string $event, string $definition): void
    {
        throw new RuntimeException('SQL Server does not support scheduled events.');
    }

    public function dropEvent(string $database, string $event): void
    {
        throw new RuntimeException('SQL Server does not support scheduled events.');
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

    public function addColumn(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($definition['name'] ?? '');
        $type = $this->sanitizeColumnType($definition['type'] ?? '');

        if (!empty($definition['autoIncrement'])) {
            $this->pdo->exec("ALTER TABLE dbo.$qTbl ADD $qCol $type IDENTITY(1,1) NOT NULL");
            return;
        }

        $null = ($definition['nullable'] ?? true) ? ' NULL' : ' NOT NULL';
        $def  = (isset($definition['default']) && $definition['default'] !== null)
                    ? ' DEFAULT ' . $this->pdo->quote((string)$definition['default'])
                    : '';
        $this->pdo->exec("ALTER TABLE dbo.$qTbl ADD $qCol $type$null$def");
    }

    public function modifyColumn(string $database, string $table, string $columnName, array $definition): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $qTbl  = $this->quoteIdent($table);
        $qOld  = $this->quoteIdent($columnName);
        $qNew  = $this->quoteIdent($definition['name'] ?? $columnName);
        $type  = $this->sanitizeColumnType($definition['type'] ?? '');
        $null  = ($definition['nullable'] ?? true) ? ' NULL' : ' NOT NULL';
        $def   = (isset($definition['default']) && $definition['default'] !== null)
                     ? ' DEFAULT ' . $this->pdo->quote((string)$definition['default'])
                     : '';

        // Retype first, then rename if needed (SQL Server doesn't combine them).
        $this->pdo->exec("ALTER TABLE dbo.$qTbl ALTER COLUMN $qOld $type$null$def");

        if ($qOld !== $qNew) {
            // sp_rename is the SQL Server way to rename columns.
            $spTable  = $this->pdo->quote("dbo.$table.$columnName");
            $spNewCol = $this->pdo->quote($definition['name']);
            $this->pdo->exec("EXEC sp_rename $spTable, $spNewCol, 'COLUMN'");
        }
    }

    public function dropColumn(string $database, string $table, string $columnName): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($columnName);
        $this->pdo->exec("ALTER TABLE dbo.$qTbl DROP COLUMN $qCol");
    }

    public function reorderColumn(string $database, string $table, string $columnName, ?string $afterColumn): void
    {
        throw new \RuntimeException(
            'SQL Server does not support changing column positions. '
            . 'To reorder columns, drop and recreate the table.'
        );
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

    public function getForeignKeys(string $database, string $table): array
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }

        $outStmt = $this->pdo->prepare(
            "SELECT fk.name AS constraint_name,
                    COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS column_name,
                    OBJECT_SCHEMA_NAME(fkc.referenced_object_id) AS ref_schema,
                    OBJECT_NAME(fkc.referenced_object_id) AS ref_table,
                    COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS ref_column,
                    fk.update_referential_action_desc AS on_update,
                    fk.delete_referential_action_desc AS on_delete
             FROM sys.foreign_keys fk
             JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
             WHERE OBJECT_NAME(fk.parent_object_id) = :tbl
             ORDER BY fk.name, fkc.constraint_column_id"
        );
        $outStmt->execute([':tbl' => $table]);
        $outgoing = [];
        foreach ($outStmt->fetchAll() as $r) {
            $outgoing[] = [
                'column'             => $r['column_name'],
                'referencedDatabase' => $database,
                'referencedTable'    => $r['ref_table'],
                'referencedColumn'   => $r['ref_column'],
                'constraintName'     => $r['constraint_name'],
                'onUpdate'           => str_replace('_', ' ', $r['on_update']),
                'onDelete'           => str_replace('_', ' ', $r['on_delete']),
            ];
        }

        $inStmt = $this->pdo->prepare(
            "SELECT fk.name AS constraint_name,
                    OBJECT_NAME(fk.parent_object_id) AS referencing_table,
                    COL_NAME(fkc.parent_object_id, fkc.parent_column_id) AS referencing_column,
                    COL_NAME(fkc.referenced_object_id, fkc.referenced_column_id) AS ref_column,
                    fk.update_referential_action_desc AS on_update,
                    fk.delete_referential_action_desc AS on_delete
             FROM sys.foreign_keys fk
             JOIN sys.foreign_key_columns fkc ON fkc.constraint_object_id = fk.object_id
             WHERE OBJECT_NAME(fk.referenced_object_id) = :tbl
             ORDER BY fk.name, fkc.constraint_column_id"
        );
        $inStmt->execute([':tbl' => $table]);
        $incoming = [];
        foreach ($inStmt->fetchAll() as $r) {
            $incoming[] = [
                'column'              => $r['ref_column'],
                'referencingDatabase' => $database,
                'referencingTable'    => $r['referencing_table'],
                'referencingColumn'   => $r['referencing_column'],
                'constraintName'      => $r['constraint_name'],
                'onUpdate'            => str_replace('_', ' ', $r['on_update']),
                'onDelete'            => str_replace('_', ' ', $r['on_delete']),
            ];
        }

        return ['outgoing' => $outgoing, 'incoming' => $incoming];
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

    public function getCreateTable(string $database, string $table): string
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $this->validateIdent($table);

        // Reconstruct a CREATE TABLE statement from INFORMATION_SCHEMA.
        $cstmt = $this->pdo->prepare(
            "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
                    NUMERIC_PRECISION, NUMERIC_SCALE,
                    IS_NULLABLE, COLUMN_DEFAULT,
                    COLUMNPROPERTY(OBJECT_ID(:tbl), COLUMN_NAME, 'IsIdentity') AS is_identity
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_NAME = :tbl2
             ORDER BY ORDINAL_POSITION"
        );
        $cstmt->execute([':tbl' => $table, ':tbl2' => $table]);
        $rows = $cstmt->fetchAll();
        if ($rows === []) {
            throw new RuntimeException("Table not found: $table");
        }

        $lines = [];
        foreach ($rows as $r) {
            $type = strtoupper($r['DATA_TYPE']);
            if ($r['CHARACTER_MAXIMUM_LENGTH'] !== null) {
                $len  = $r['CHARACTER_MAXIMUM_LENGTH'] == -1 ? 'MAX' : $r['CHARACTER_MAXIMUM_LENGTH'];
                $type = "$type($len)";
            } elseif ($r['NUMERIC_PRECISION'] !== null && in_array($type, ['DECIMAL', 'NUMERIC'], true)) {
                $type = "$type({$r['NUMERIC_PRECISION']},{$r['NUMERIC_SCALE']})";
            }
            $null   = $r['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL';
            $id     = (int)$r['is_identity'] ? ' IDENTITY(1,1)' : '';
            $def    = $r['COLUMN_DEFAULT'] !== null ? " DEFAULT {$r['COLUMN_DEFAULT']}" : '';
            $lines[] = "    [{$r['COLUMN_NAME']}] $type$id $null$def";
        }

        return "CREATE TABLE [dbo].[$table] (\n" . implode(",\n", $lines) . "\n)";
    }

    public function explainQuery(string $database, string $sql): array
    {
        $this->ensureConnected();
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            throw new RuntimeException('explainQuery only accepts SELECT statements.');
        }
        if (preg_match('/;\s*\S/', rtrim($sql, "; \t\n\r"))) {
            throw new RuntimeException('explainQuery accepts only a single statement.');
        }
        if ($database !== '') {
            $this->pdo->exec("USE " . $this->quoteIdent($this->validateIdent($database)));
        }
        $this->pdo->exec('SET SHOWPLAN_ALL ON');
        try {
            $stmt = $this->pdo->query($sql);
            return $stmt->fetchAll();
        } finally {
            $this->pdo->exec('SET SHOWPLAN_ALL OFF');
        }
    }

    public function createIndex(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec('USE ' . $this->quoteIdent($this->validateIdent($database)));
        }
        $qDb  = $database !== '' ? $this->quoteIdent($database) . '.' : '';
        $qTbl = $this->quoteIdent($table);
        $cols = implode(', ', array_map([$this, 'quoteIdent'], $definition['columns']));

        if (!empty($definition['primary'])) {
            $this->pdo->exec("ALTER TABLE {$qDb}dbo.$qTbl ADD PRIMARY KEY ($cols)");
        } else {
            $this->validateIdent($definition['name']);
            $qIdx = $this->quoteIdent($definition['name']);
            $uniq = !empty($definition['unique']) ? 'UNIQUE ' : '';
            $this->pdo->exec("CREATE {$uniq}INDEX $qIdx ON {$qDb}dbo.$qTbl ($cols)");
        }
    }

    public function dropIndex(string $database, string $table, string $indexName, bool $isPrimary): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec('USE ' . $this->quoteIdent($this->validateIdent($database)));
        }
        $qDb  = $database !== '' ? $this->quoteIdent($database) . '.' : '';
        $qTbl = $this->quoteIdent($table);

        if ($isPrimary) {
            // The PK constraint name IS the index name in SQL Server's sys.indexes.
            $this->validateIdent($indexName);
            $qCons = $this->quoteIdent($indexName);
            $this->pdo->exec("ALTER TABLE {$qDb}dbo.$qTbl DROP CONSTRAINT $qCons");
        } else {
            $this->validateIdent($indexName);
            $qIdx = $this->quoteIdent($indexName);
            $this->pdo->exec("DROP INDEX $qIdx ON {$qDb}dbo.$qTbl");
        }
    }

    public function createForeignKey(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec('USE ' . $this->quoteIdent($this->validateIdent($database)));
        }
        $this->validateIdent($definition['name']);
        $this->validateIdent($definition['referencedTable']);
        $qDb     = $database !== '' ? $this->quoteIdent($database) . '.' : '';
        $qTbl    = $this->quoteIdent($table);
        $qCons   = $this->quoteIdent($definition['name']);
        $qRefTbl = $this->quoteIdent($definition['referencedTable']);
        $cols    = implode(', ', array_map([$this, 'quoteIdent'], $definition['columns']));
        $refCols = implode(', ', array_map([$this, 'quoteIdent'], $definition['referencedColumns']));
        $onUpdate = $this->sanitizeReferentialAction($definition['onUpdate'] ?? 'NO ACTION');
        $onDelete = $this->sanitizeReferentialAction($definition['onDelete'] ?? 'NO ACTION');

        $this->pdo->exec(
            "ALTER TABLE {$qDb}dbo.$qTbl ADD CONSTRAINT $qCons "
            . "FOREIGN KEY ($cols) REFERENCES {$qDb}dbo.$qRefTbl ($refCols) "
            . "ON UPDATE $onUpdate ON DELETE $onDelete"
        );
    }

    public function dropForeignKey(string $database, string $table, string $constraintName): void
    {
        $this->ensureConnected();
        if ($database !== '') {
            $this->pdo->exec('USE ' . $this->quoteIdent($this->validateIdent($database)));
        }
        $this->validateIdent($constraintName);
        $qDb   = $database !== '' ? $this->quoteIdent($database) . '.' : '';
        $qTbl  = $this->quoteIdent($table);
        $qCons = $this->quoteIdent($constraintName);
        $this->pdo->exec("ALTER TABLE {$qDb}dbo.$qTbl DROP CONSTRAINT $qCons");
    }

    public function listDatabaseCollations(string $database): array
    {
        $this->ensureConnected();
        // All supported collations available on the server
        $stmt = $this->pdo->query('SELECT name FROM sys.fn_helpcollations() ORDER BY name');
        return array_map(static fn($r) => $r['name'], $stmt->fetchAll());
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

    public function renameDatabase(string $database, string $newName): void
    {
        $this->ensureConnected();
        $this->validateIdent($database);
        $this->validateIdent($newName);
        $this->pdo->exec("ALTER DATABASE [$database] MODIFY NAME = [$newName]");
    }

    public function alterDatabaseCollation(string $database, string $collation): void
    {
        $this->ensureConnected();
        $this->validateIdent($database);
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $collation)) {
            throw new RuntimeException("Invalid collation name: $collation");
        }
        $this->pdo->exec("ALTER DATABASE [$database] COLLATE $collation");
    }

    public function dropDatabase(string $database): void
    {
        $this->ensureConnected();
        $this->validateIdent($database);
        $this->pdo->exec("DROP DATABASE [$database]");
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
