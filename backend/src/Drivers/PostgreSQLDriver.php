<?php declare(strict_types=1);

namespace Quermy\Drivers;

use PDO;
use PDOException;
use Quermy\Drivers\Capabilities\ProvidesColumnTypes;
use Quermy\Drivers\Capabilities\ProvidesListTablesQuery;
use Quermy\Drivers\Capabilities\ProvidesStructureQueryTemplate;
use Quermy\Drivers\Capabilities\ProvidesWelcomeQuery;
use Quermy\Drivers\Capabilities\SupportsAddColumn;
use Quermy\Drivers\Capabilities\SupportsDropColumn;
use Quermy\Drivers\Capabilities\SupportsDropDatabase;
use Quermy\Drivers\Capabilities\SupportsExplain;
use Quermy\Drivers\Capabilities\SupportsForeignKeyManagement;
use Quermy\Drivers\Capabilities\SupportsForeignKeys;
use Quermy\Drivers\Capabilities\SupportsFunctionManagement;
use Quermy\Drivers\Capabilities\SupportsGetCreateTable;
use Quermy\Drivers\Capabilities\SupportsIndexManagement;
use Quermy\Drivers\Capabilities\SupportsModifyColumn;
use Quermy\Drivers\Capabilities\SupportsProcedureManagement;
use Quermy\Drivers\Capabilities\SupportsRenameDatabase;
use Quermy\Drivers\Capabilities\SupportsViewManagement;
use RuntimeException;

/**
 * PostgreSQL driver (pdo_pgsql).
 *
 * Identifiers are quoted with double-quotes.
 * Schemas: the driver targets the `public` schema by default; the
 * "database" field maps to a PostgreSQL database (not a schema).
 */
class PostgreSQLDriver implements
    DriverInterface,
    SupportsAddColumn,
    SupportsModifyColumn,
    SupportsDropColumn,
    SupportsIndexManagement,
    SupportsForeignKeys,
    SupportsForeignKeyManagement,
    SupportsGetCreateTable,
    SupportsExplain,
    SupportsRenameDatabase,
    SupportsDropDatabase,
    SupportsViewManagement,
    SupportsProcedureManagement,
    SupportsFunctionManagement,
    ProvidesColumnTypes,
    ProvidesWelcomeQuery,
    ProvidesStructureQueryTemplate,
    ProvidesListTablesQuery
{
    private ?PDO $pdo = null;

    public static function engineId(): string
    {
        return 'postgresql';
    }

    public static function engineMeta(): array
    {
        return [
            'id'              => 'postgresql',
            'label'           => 'PostgreSQL',
            'defaultPort'     => 5432,
            'defaultUsername' => 'postgres',
            'connectionType'  => 'tcp',
            'identifierOpen'  => '"',
            'identifierClose' => '"',
        ];
    }

    public function columnTypes(): array
    {
        return [
            // Numeric
            'SMALLINT', 'INTEGER', 'BIGINT',
            'DECIMAL', 'NUMERIC(10,2)', 'REAL', 'DOUBLE PRECISION',
            'SMALLSERIAL', 'SERIAL', 'BIGSERIAL',
            // String
            'CHAR(1)', 'VARCHAR(255)', 'TEXT',
            // Binary
            'BYTEA',
            // Date/Time
            'DATE', 'TIME', 'TIMESTAMP', 'TIMESTAMPTZ', 'INTERVAL',
            // Boolean
            'BOOLEAN',
            // Network
            'INET', 'CIDR', 'MACADDR',
            // Other
            'JSON', 'JSONB', 'UUID', 'XML',
            'INTEGER[]', 'TEXT[]',
        ];
    }

    public function welcomeQuery(): string
    {
        return 'SELECT NOW() AS now, version() AS version;';
    }

    public function structureQueryTemplate(): string
    {
        return "SELECT column_name, data_type, is_nullable, column_default\nFROM information_schema.columns\nWHERE table_schema = 'public' AND table_name = '{table}'\nORDER BY ordinal_position;";
    }

    public function listTablesQuery(): string
    {
        return "SELECT tablename\nFROM pg_catalog.pg_tables\nWHERE schemaname = 'public'\nORDER BY tablename;";
    }

    public function connect(array $config): void
    {
        $host = $config['host'] ?? '127.0.0.1';
        $port = (int)($config['port'] ?? 5432);
        $user = $config['username'] ?? '';
        $pass = $config['password'] ?? '';
        $db   = $config['database'] ?? 'postgres';

        $dsn = "pgsql:host=$host;port=$port;dbname=$db";

        try {
            $this->pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_TIMEOUT            => 5,
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
            "SELECT datname FROM pg_database
             WHERE datistemplate = false
               AND datname NOT IN ('postgres')
             ORDER BY datname"
        );
        return array_map(static fn($r) => $r['datname'], $stmt->fetchAll());
    }

    public function listTables(string $database): array
    {
        $this->ensureConnected();
        // In PostgreSQL the "database" is the connection target; we list
        // tables in the 'public' schema of the currently-connected database.
        $stmt = $this->pdo->query(
            "SELECT relname AS table_name,
                    GREATEST(reltuples::BIGINT, 0) AS row_estimate,
                    pg_total_relation_size(oid) AS total_size
             FROM pg_class
             WHERE relkind = 'r'
               AND relnamespace = (SELECT oid FROM pg_namespace WHERE nspname = 'public')
             ORDER BY relname"
        );
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[] = [
                'name' => $row['table_name'],
                'rows' => (int)$row['row_estimate'],
                'size' => (int)$row['total_size'],
            ];
        }
        return $out;
    }

    public function listViews(string $database): array
    {
        $this->ensureConnected();
        $stmt = $this->pdo->query(
            "SELECT table_name
             FROM information_schema.views
             WHERE table_schema = 'public'
             ORDER BY table_name"
        );
        return array_map(static fn($r) => $r['table_name'], $stmt->fetchAll());
    }

    public function getViewDefinition(string $database, string $view): string
    {
        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            "SELECT view_definition
             FROM information_schema.views
             WHERE table_schema = 'public' AND table_name = :view"
        );
        $stmt->execute([':view' => $view]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new RuntimeException("View not found: $view");
        }
        return trim((string)($row['view_definition'] ?? ''));
    }

    public function upsertView(string $database, string $view, string $definition): void
    {
        $this->ensureConnected();
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
        $qView = $this->quoteIdent($name);
        $this->pdo->exec("CREATE OR REPLACE VIEW public.$qView AS\n$body");
    }

    public function dropView(string $database, string $view): void
    {
        $this->ensureConnected();
        $name  = $this->validateIdent($view);
        $qView = $this->quoteIdent($name);
        $this->pdo->exec("DROP VIEW IF EXISTS public.$qView");
    }

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

    public function browseTable(string $database, string $table, int $limit, int $offset): array
    {
        $this->ensureConnected();
        $this->validateIdent($database);
        $this->validateIdent($table);

        // Column metadata
        $cstmt = $this->pdo->prepare(
            "SELECT column_name, data_type, udt_name, is_nullable, column_default,
                    column_name IN (
                        SELECT kcu.column_name
                        FROM information_schema.table_constraints tc
                        JOIN information_schema.key_column_usage kcu
                          ON kcu.constraint_name = tc.constraint_name
                         AND kcu.table_schema    = tc.table_schema
                        WHERE tc.constraint_type = 'PRIMARY KEY'
                          AND tc.table_schema    = 'public'
                          AND tc.table_name      = :tbl
                    ) AS is_pk,
                    (column_default LIKE 'nextval(%' OR is_identity = 'YES') AS is_identity
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = :tbl
             ORDER BY ordinal_position"
        );
        $cstmt->execute([':tbl' => $table]);
        $columns = [];
        foreach ($cstmt->fetchAll() as $c) {
            $isPk       = (bool)$c['is_pk'];
            $isIdentity = (bool)$c['is_identity'];
            $columns[] = [
                'name'          => $c['column_name'],
                'type'          => strtoupper($c['udt_name'] !== $c['data_type'] ? $c['data_type'] : $c['data_type']),
                'nullable'      => $c['is_nullable'] === 'YES',
                'key'           => $isPk ? 'primary' : null,
                'default'       => $c['column_default'],
                'extra'         => $isIdentity ? 'auto_increment' : '',
                'autoIncrement' => $isIdentity,
            ];
        }

        // Total rows
        $qTbl  = $this->quoteIdent($table);
        $total = (int)$this->pdo->query("SELECT COUNT(*) AS c FROM public.$qTbl")->fetch()['c'];

        // Data
        $limit  = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $rstmt  = $this->pdo->query("SELECT * FROM public.$qTbl LIMIT $limit OFFSET $offset");
        $rows   = $rstmt->fetchAll();

        return ['columns' => $columns, 'rows' => $rows, 'total' => $total];
    }

    public function runQuery(string $database, string $sql): array
    {
        $this->ensureConnected();

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
        $qTbl  = $this->quoteIdent($table);
        $cols  = implode(', ', array_map([$this, 'quoteIdent'], array_keys($values)));
        $phs   = implode(', ', array_fill(0, count($values), '?'));
        $stmt  = $this->pdo->prepare("INSERT INTO public.$qTbl ($cols) VALUES ($phs) RETURNING *");
        $stmt->execute(array_values($values));
        $row = $stmt->fetch();
        try {
            $insertId = (int)$this->pdo->query('SELECT lastval()')->fetchColumn();
        } catch (\Exception $e) {
            $insertId = 0;
        }
        return ['affected' => $stmt->rowCount(), 'insertId' => $insertId];
    }

    public function updateRow(string $database, string $table, array $where, array $values): array
    {
        $this->ensureConnected();
        if (empty($where))  throw new RuntimeException('No WHERE conditions provided');
        if (empty($values)) throw new RuntimeException('No values to update');

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

        $sql  = "UPDATE public.$qTbl SET " . implode(', ', $setClauses)
              . ' WHERE '                  . implode(' AND ', $whereClauses);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return ['affected' => $stmt->rowCount()];
    }

    public function deleteRow(string $database, string $table, array $where): array
    {
        $this->ensureConnected();
        if (empty($where)) throw new RuntimeException('No WHERE conditions provided');

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

        $sql  = "DELETE FROM public.$qTbl WHERE " . implode(' AND ', $whereClauses);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return ['affected' => $stmt->rowCount()];
    }

    public function addColumn(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($definition['name'] ?? '');
        $type = $this->sanitizeColumnType($definition['type'] ?? '');

        // For autoIncrement use GENERATED ALWAYS AS IDENTITY (PostgreSQL 10+)
        if (!empty($definition['autoIncrement'])) {
            $this->pdo->exec("ALTER TABLE public.$qTbl ADD COLUMN $qCol $type GENERATED ALWAYS AS IDENTITY");
            return;
        }

        $null = ($definition['nullable'] ?? true) ? '' : ' NOT NULL';
        $def  = (isset($definition['default']) && $definition['default'] !== null)
                    ? ' DEFAULT ' . $this->pdo->quote((string)$definition['default'])
                    : (($definition['nullable'] ?? true) ? ' DEFAULT NULL' : '');

        $this->pdo->exec("ALTER TABLE public.$qTbl ADD COLUMN $qCol $type$null$def");
    }

    public function modifyColumn(string $database, string $table, string $columnName, array $definition): void
    {
        $this->ensureConnected();
        $qTbl  = $this->quoteIdent($table);
        $qOld  = $this->quoteIdent($columnName);
        $qNew  = $this->quoteIdent($definition['name'] ?? $columnName);
        $type  = $this->sanitizeColumnType($definition['type'] ?? '');
        $null  = ($definition['nullable'] ?? true);

        // PostgreSQL requires separate ALTER COLUMN statements for each attribute.
        $this->pdo->exec("ALTER TABLE public.$qTbl ALTER COLUMN $qOld TYPE $type USING $qOld::$type");

        if ($null) {
            $this->pdo->exec("ALTER TABLE public.$qTbl ALTER COLUMN $qOld DROP NOT NULL");
        } else {
            $this->pdo->exec("ALTER TABLE public.$qTbl ALTER COLUMN $qOld SET NOT NULL");
        }

        if (isset($definition['default']) && $definition['default'] !== null) {
            $this->pdo->exec("ALTER TABLE public.$qTbl ALTER COLUMN $qOld SET DEFAULT " . $this->pdo->quote((string)$definition['default']));
        } else {
            $this->pdo->exec("ALTER TABLE public.$qTbl ALTER COLUMN $qOld DROP DEFAULT");
        }

        // Rename last to avoid ambiguity in the statements above.
        if ($qOld !== $qNew) {
            $this->pdo->exec("ALTER TABLE public.$qTbl RENAME COLUMN $qOld TO $qNew");
        }
    }

    public function dropColumn(string $database, string $table, string $columnName): void
    {
        $this->ensureConnected();
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($columnName);
        $this->pdo->exec("ALTER TABLE public.$qTbl DROP COLUMN $qCol");
    }

    public function describeTable(string $database, string $table): array
    {
        $this->ensureConnected();

        $cstmt = $this->pdo->prepare(
            "SELECT c.column_name, c.data_type, c.is_nullable, c.column_default,
                    (c.column_default LIKE 'nextval(%' OR c.is_identity = 'YES') AS is_identity,
                    COALESCE(pgd.description, '') AS comment,
                    tc.constraint_type AS key_type
             FROM information_schema.columns c
             LEFT JOIN pg_catalog.pg_statio_all_tables AS st
               ON st.schemaname = 'public' AND st.relname = c.table_name
             LEFT JOIN pg_catalog.pg_description pgd
               ON pgd.objoid = st.relid
              AND pgd.objsubid = c.ordinal_position
             LEFT JOIN information_schema.key_column_usage kcu
               ON kcu.table_schema  = c.table_schema
              AND kcu.table_name    = c.table_name
              AND kcu.column_name   = c.column_name
             LEFT JOIN information_schema.table_constraints tc
               ON tc.constraint_name   = kcu.constraint_name
              AND tc.table_schema      = kcu.table_schema
              AND tc.constraint_type   = 'PRIMARY KEY'
             WHERE c.table_schema = 'public' AND c.table_name = :tbl
             ORDER BY c.ordinal_position"
        );
        $cstmt->execute([':tbl' => $table]);
        $columns = [];
        foreach ($cstmt->fetchAll() as $c) {
            $isIdentity = (bool)$c['is_identity'];
            $keyType    = $c['key_type'] === 'PRIMARY KEY' ? 'primary' : null;
            $columns[]  = [
                'name'          => $c['column_name'],
                'type'          => strtoupper($c['data_type']),
                'nullable'      => $c['is_nullable'] === 'YES',
                'key'           => $keyType,
                'default'       => $c['column_default'],
                'extra'         => $isIdentity ? 'auto_increment' : '',
                'comment'       => $c['comment'],
                'autoIncrement' => $isIdentity,
            ];
        }

        if ($columns === []) {
            throw new RuntimeException("Table not found or not accessible: $table");
        }

        // Indexes
        $istmt = $this->pdo->prepare(
            "SELECT i.relname AS index_name,
                    a.attname AS column_name,
                    ix.indisunique AS is_unique
             FROM pg_index ix
             JOIN pg_class t  ON t.oid  = ix.indrelid
             JOIN pg_class i  ON i.oid  = ix.indexrelid
             JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
             JOIN pg_namespace n ON n.oid = t.relnamespace
             WHERE t.relname = :tbl AND n.nspname = 'public'
             ORDER BY i.relname, a.attnum"
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
        foreach ($byIndex as $idx) {
            if ($idx['unique'] && count($idx['columns']) > 0) {
                // Heuristic: primary key index usually ends with _pkey
                if (str_ends_with($idx['name'], '_pkey')) {
                    $primaryKey = $idx['columns'];
                    break;
                }
            }
        }

        return ['columns' => $columns, 'primaryKey' => $primaryKey, 'indexes' => array_values($byIndex)];
    }

    public function getForeignKeys(string $database, string $table): array
    {
        $this->ensureConnected();

        $outStmt = $this->pdo->prepare(
            "SELECT kcu.column_name, ccu.table_schema AS referenced_schema,
                    ccu.table_name AS referenced_table, ccu.column_name AS referenced_column,
                    tc.constraint_name, rc.update_rule, rc.delete_rule
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
             JOIN information_schema.referential_constraints rc
               ON rc.constraint_name = tc.constraint_name AND rc.constraint_schema = tc.table_schema
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
             WHERE tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_schema = 'public' AND tc.table_name = :tbl
             ORDER BY tc.constraint_name, kcu.ordinal_position"
        );
        $outStmt->execute([':tbl' => $table]);
        $outgoing = [];
        foreach ($outStmt->fetchAll() as $r) {
            $outgoing[] = [
                'column'             => $r['column_name'],
                'referencedDatabase' => $r['referenced_schema'],
                'referencedTable'    => $r['referenced_table'],
                'referencedColumn'   => $r['referenced_column'],
                'constraintName'     => $r['constraint_name'],
                'onUpdate'           => $r['update_rule'],
                'onDelete'           => $r['delete_rule'],
            ];
        }

        $inStmt = $this->pdo->prepare(
            "SELECT kcu.table_name AS referencing_table, kcu.column_name AS referencing_column,
                    ccu.column_name AS referenced_column, tc.constraint_name,
                    rc.update_rule, rc.delete_rule
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
             JOIN information_schema.referential_constraints rc
               ON rc.constraint_name = tc.constraint_name AND rc.constraint_schema = tc.table_schema
             JOIN information_schema.constraint_column_usage ccu
               ON ccu.constraint_name = tc.constraint_name AND ccu.table_schema = tc.table_schema
             WHERE tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_schema = 'public' AND ccu.table_name = :tbl
             ORDER BY kcu.table_name, tc.constraint_name"
        );
        $inStmt->execute([':tbl' => $table]);
        $incoming = [];
        foreach ($inStmt->fetchAll() as $r) {
            $incoming[] = [
                'column'              => $r['referenced_column'],
                'referencingDatabase' => 'public',
                'referencingTable'    => $r['referencing_table'],
                'referencingColumn'   => $r['referencing_column'],
                'constraintName'      => $r['constraint_name'],
                'onUpdate'            => $r['update_rule'],
                'onDelete'            => $r['delete_rule'],
            ];
        }

        return ['outgoing' => $outgoing, 'incoming' => $incoming];
    }

    public function sampleTable(string $database, string $table, int $limit): array
    {
        $this->ensureConnected();
        $this->validateIdent($table);
        $limit = max(1, min(20, $limit));
        $qTbl  = $this->quoteIdent($table);

        $stmt  = $this->pdo->query("SELECT * FROM public.$qTbl LIMIT $limit");
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
        $like    = '%' . $this->escapeLike($term) . '%';
        $tables  = [];
        $columns = [];

        if ($scope === 'tables' || $scope === 'all') {
            $stmt = $this->pdo->prepare(
                "SELECT t.relname AS table_name,
                        COALESCE(obj_description(t.oid), '') AS comment
                 FROM pg_class t
                 JOIN pg_namespace n ON n.oid = t.relnamespace
                 WHERE t.relkind = 'r' AND n.nspname = 'public'
                   AND (t.relname ILIKE :term OR obj_description(t.oid) ILIKE :term2)
                 ORDER BY t.relname
                 LIMIT 100"
            );
            $stmt->execute([':term' => $like, ':term2' => $like]);
            foreach ($stmt->fetchAll() as $r) {
                $tables[] = ['name' => $r['table_name'], 'comment' => $r['comment']];
            }
        }

        if ($scope === 'columns' || $scope === 'all') {
            $stmt = $this->pdo->prepare(
                "SELECT c.table_name, c.column_name, c.data_type,
                        COALESCE(pgd.description, '') AS comment
                 FROM information_schema.columns c
                 LEFT JOIN pg_catalog.pg_statio_all_tables st
                   ON st.schemaname = 'public' AND st.relname = c.table_name
                 LEFT JOIN pg_catalog.pg_description pgd
                   ON pgd.objoid = st.relid AND pgd.objsubid = c.ordinal_position
                 WHERE c.table_schema = 'public'
                   AND (c.column_name ILIKE :term OR pgd.description ILIKE :term2)
                 ORDER BY c.table_name, c.ordinal_position
                 LIMIT 200"
            );
            $stmt->execute([':term' => $like, ':term2' => $like]);
            foreach ($stmt->fetchAll() as $r) {
                $columns[] = [
                    'table'   => $r['table_name'],
                    'name'    => $r['column_name'],
                    'type'    => strtoupper($r['data_type']),
                    'comment' => $r['comment'],
                ];
            }
        }

        return ['tables' => $tables, 'columns' => $columns];
    }

    public function getCreateTable(string $database, string $table): string
    {
        $this->ensureConnected();
        $this->validateIdent($table);

        // pg_get_tabledef is not a built-in; reconstruct from catalog.
        $qTbl = $this->pdo->quote($table);
        $stmt = $this->pdo->query(
            "SELECT 'CREATE TABLE public.' || quote_ident(c.relname) || ' (' || chr(10) || "
            . "string_agg("
            . "  '  ' || quote_ident(a.attname) || ' ' || pg_catalog.format_type(a.atttypid, a.atttypmod)"
            . "  || CASE WHEN a.attnotnull THEN ' NOT NULL' ELSE '' END"
            . "  || CASE WHEN ad.adbin IS NOT NULL THEN ' DEFAULT ' || pg_get_expr(ad.adbin, ad.adrelid) ELSE '' END,"
            . "  chr(10) || ','"
            . "  ORDER BY a.attnum"
            . ") || chr(10) || ');' AS ddl "
            . "FROM pg_class c "
            . "JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped "
            . "LEFT JOIN pg_attrdef ad ON ad.adrelid = c.oid AND ad.adnum = a.attnum "
            . "JOIN pg_namespace n ON n.oid = c.relnamespace "
            . "WHERE c.relname = $qTbl AND n.nspname = 'public' "
            . "GROUP BY c.relname"
        );
        $row = $stmt->fetch();
        if (!$row || !$row['ddl']) {
            throw new RuntimeException("Table not found: $table");
        }
        return $row['ddl'];
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
        $stmt = $this->pdo->query('EXPLAIN (FORMAT JSON) ' . $sql);
        $rows = $stmt->fetchAll();
        // PostgreSQL EXPLAIN (FORMAT JSON) returns one row with a JSON string.
        if (isset($rows[0]['QUERY PLAN'])) {
            $decoded = json_decode($rows[0]['QUERY PLAN'], true);
            return is_array($decoded) ? $decoded : $rows;
        }
        return $rows;
    }

    public function createIndex(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        $this->validateIdent($table);
        $qTbl = $this->quoteIdent($table);
        $cols = implode(', ', array_map([$this, 'quoteIdent'], $definition['columns']));

        if (!empty($definition['primary'])) {
            $this->pdo->exec("ALTER TABLE public.$qTbl ADD PRIMARY KEY ($cols)");
        } else {
            $this->validateIdent($definition['name']);
            $qIdx = $this->quoteIdent($definition['name']);
            $uniq = !empty($definition['unique']) ? 'UNIQUE ' : '';
            $this->pdo->exec("CREATE {$uniq}INDEX $qIdx ON public.$qTbl ($cols)");
        }
    }

    public function dropIndex(string $database, string $table, string $indexName, bool $isPrimary): void
    {
        $this->ensureConnected();
        $this->validateIdent($table);
        $qTbl = $this->quoteIdent($table);

        if ($isPrimary) {
            // Use the provided index name — it IS the constraint name in PostgreSQL (e.g. table_pkey).
            $this->validateIdent($indexName);
            $qCons = $this->quoteIdent($indexName);
            $this->pdo->exec("ALTER TABLE public.$qTbl DROP CONSTRAINT $qCons");
        } else {
            $this->validateIdent($indexName);
            $qIdx = $this->quoteIdent($indexName);
            $this->pdo->exec("DROP INDEX public.$qIdx");
        }
    }

    public function createForeignKey(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        $this->validateIdent($table);
        $this->validateIdent($definition['name']);
        $this->validateIdent($definition['referencedTable']);
        $qTbl    = $this->quoteIdent($table);
        $qCons   = $this->quoteIdent($definition['name']);
        $qRefTbl = $this->quoteIdent($definition['referencedTable']);
        $cols    = implode(', ', array_map([$this, 'quoteIdent'], $definition['columns']));
        $refCols = implode(', ', array_map([$this, 'quoteIdent'], $definition['referencedColumns']));
        $onUpdate = $this->sanitizeReferentialAction($definition['onUpdate'] ?? 'RESTRICT');
        $onDelete = $this->sanitizeReferentialAction($definition['onDelete'] ?? 'RESTRICT');

        $this->pdo->exec(
            "ALTER TABLE public.$qTbl ADD CONSTRAINT $qCons "
            . "FOREIGN KEY ($cols) REFERENCES public.$qRefTbl ($refCols) "
            . "ON UPDATE $onUpdate ON DELETE $onDelete"
        );
    }

    public function dropForeignKey(string $database, string $table, string $constraintName): void
    {
        $this->ensureConnected();
        $this->validateIdent($table);
        $this->validateIdent($constraintName);
        $qTbl  = $this->quoteIdent($table);
        $qCons = $this->quoteIdent($constraintName);
        $this->pdo->exec("ALTER TABLE public.$qTbl DROP CONSTRAINT $qCons");
    }

    public function getDatabaseInfo(string $database): array
    {
        $this->ensureConnected();
        $stmt = $this->pdo->prepare(
            'SELECT datname, pg_encoding_to_char(encoding) AS charset, datcollate AS collation ' .
            'FROM pg_database WHERE datname = :db'
        );
        $stmt->execute([':db' => $database]);
        $row = $stmt->fetch();
        return [
            'name'      => $row['datname']   ?? $database,
            'charset'   => $row['charset']   ?? null,
            'collation' => $row['collation'] ?? null,
        ];
    }

    public function renameDatabase(string $database, string $newName): void
    {
        $this->ensureConnected();
        $qDb  = $this->quoteIdent($database);
        $qNew = $this->quoteIdent($newName);
        // Note: ALTER DATABASE RENAME TO cannot rename the currently connected database.
        // The calling user must be connected to a different database for this to succeed.
        $this->pdo->exec("ALTER DATABASE $qDb RENAME TO $qNew");
    }

    public function dropDatabase(string $database): void
    {
        $this->ensureConnected();
        $qDb = $this->quoteIdent($database);
        // Note: DROP DATABASE cannot target the currently connected database.
        $this->pdo->exec("DROP DATABASE $qDb");
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

    private function quoteIdent(string $name): string
    {
        if ($name === '') throw new RuntimeException('Empty identifier');
        return '"' . str_replace('"', '""', $name) . '"';
    }

    private function validateIdent(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException("Invalid identifier: $name");
        }
        return $name;
    }

    private function sanitizeColumnType(string $type): string
    {
        $type = trim($type);
        if ($type === '') throw new RuntimeException('Column type cannot be empty');
        if (!preg_match('/^[A-Za-z0-9_()\s,\[\]]+$/', $type)) {
            throw new RuntimeException('Invalid column type');
        }
        if (strlen($type) > 100) throw new RuntimeException('Column type too long');
        return strtoupper($type);
    }

    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
