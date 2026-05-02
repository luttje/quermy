<?php declare(strict_types=1);

namespace Quermy\Drivers;

use PDO;
use PDOException;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportAddColumn;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportDropColumn;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportDropDatabase;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportDropTable;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportExplain;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportForeignKeyManagement;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportForeignKeys;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportFunctionManagement;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportGetCreateTable;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportIndexManagement;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportModifyColumn;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportProcedureManagement;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportRenameDatabase;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportTruncateTable;
use Quermy\Drivers\Capabilities\PostgreSQL\SupportViewManagement;
use Quermy\Drivers\Capabilities\ProvidesColumnTypes;
use Quermy\Drivers\Capabilities\ProvidesColumnTypesWithLength;
use Quermy\Drivers\Capabilities\ProvidesDefaultColumnType;
use Quermy\Drivers\Capabilities\ProvidesListTablesQuery;
use Quermy\Drivers\Capabilities\ProvidesReferentialActions;
use Quermy\Drivers\Capabilities\ProvidesStructureQueryTemplate;
use Quermy\Drivers\Capabilities\ProvidesTextColumnTypePatterns;
use Quermy\Drivers\Capabilities\ProvidesWelcomeQuery;
use Quermy\Drivers\Capabilities\SupportsAddColumn;
use Quermy\Drivers\Capabilities\SupportsDropColumn;
use Quermy\Drivers\Capabilities\SupportsDropDatabase;
use Quermy\Drivers\Capabilities\SupportsDropTable;
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
use Quermy\Drivers\Capabilities\SupportsTruncateTable;
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
    ProvidesColumnTypes,
    ProvidesColumnTypesWithLength,
    ProvidesDefaultColumnType,
    ProvidesListTablesQuery,
    ProvidesReferentialActions,
    ProvidesStructureQueryTemplate,
    ProvidesTextColumnTypePatterns,
    ProvidesWelcomeQuery,
    SupportsAddColumn,
    SupportsDropColumn,
    SupportsDropDatabase,
    SupportsDropTable,
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
    SupportsTruncateTable,
    SupportsViewManagement
{
    use SupportAddColumn,
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
            'DECIMAL', 'NUMERIC', 'REAL', 'DOUBLE PRECISION',
            'SMALLSERIAL', 'SERIAL', 'BIGSERIAL',
            // String
            'CHAR', 'VARCHAR', 'TEXT',
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

    public function columnTypesWithLength(): array
    {
        return ['CHAR', 'VARCHAR', 'DECIMAL', 'NUMERIC'];
    }

    public function defaultColumnType(): string
    {
        return 'TEXT';
    }

    public function referentialActions(): array
    {
        return ['RESTRICT', 'CASCADE', 'SET NULL', 'SET DEFAULT', 'NO ACTION'];
    }

    public function textColumnTypePatterns(): array
    {
        return ['char', 'text'];
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

    /*
     * Private helpers
     */

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
