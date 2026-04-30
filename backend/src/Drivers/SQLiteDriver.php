<?php declare(strict_types=1);

namespace Quermy\Drivers;

use PDO;
use PDOException;
use RuntimeException;

/**
 * SQLite driver (pdo_sqlite).
 *
 * The "database" config field is the file path (or ":memory:" for in-memory).
 * host / port / username / password are ignored.
 *
 * Notable limitations compared to server-based engines:
 * - There is only one logical "database" (named "main").
 * - ALTER TABLE only supports ADD COLUMN; modifying or dropping columns
 *   requires SQLite 3.35+ for DROP COLUMN and is not supported for renames/
 *   retypes (the driver throws explicitly for unsupported operations).
 */
class SQLiteDriver implements DriverInterface
{
    private ?PDO $pdo = null;

    public static function engineId(): string
    {
        return 'sqlite';
    }

    public static function engineMeta(): array
    {
        return [
            'id'              => 'sqlite',
            'label'           => 'SQLite',
            'defaultPort'     => 0,
            'defaultUsername' => '',
            'connectionType'  => 'file',
            'identifierOpen'  => '"',
            'identifierClose' => '"',
        ];
    }

    public function getCapabilities(): array
    {
        $dropColumnSupported = $this->pdo !== null
            ? $this->sqliteVersionAtLeast('3.35.0')
            : true; // assume yes if not connected yet

        return [
            'columnTypes' => [
                // Affinities & common types
                'INTEGER', 'INT', 'TINYINT', 'SMALLINT', 'MEDIUMINT', 'BIGINT',
                'UNSIGNED BIG INT', 'INT2', 'INT8',
                'REAL', 'DOUBLE', 'DOUBLE PRECISION', 'FLOAT',
                'NUMERIC', 'DECIMAL(10,2)',
                'TEXT', 'CHARACTER(20)', 'VARCHAR(255)', 'VARYING CHARACTER(255)', 'NCHAR(55)', 'NATIVE CHARACTER(70)', 'NVARCHAR(100)', 'CLOB',
                'BLOB',
                'BOOLEAN',
                'DATE', 'DATETIME',
            ],
            'supportsAutoIncrement'  => true,   // INTEGER PRIMARY KEY AUTOINCREMENT
            'supportsColumnAfter'    => false,
            'supportsModifyColumn'   => false,   // not supported; driver throws
            'supportsDropColumn'     => $dropColumnSupported,
            'supportsReorderColumn'  => false,
            'supportsGetCreateTable' => true,
            'supportsExplain'        => true,
            'supportsForeignKeys'    => true,
            'supportsIndexManagement'       => true,
            'supportsPrimaryKeyManagement'  => false,
            'supportsForeignKeyManagement'  => false,
            'supportsRenameDatabase'         => false,
            'supportsAlterDatabaseCollation' => false,
            'supportsDropDatabase'           => false,
            'welcomeQuery'           => 'SELECT sqlite_version() AS version;',
            'structureQueryTemplate' => 'PRAGMA table_info("{table}");',
            'identifierOpen'         => '"',
            'identifierClose'        => '"',
        ];
    }

    public function connect(array $config): void
    {
        $path = $config['database'] ?? ':memory:';

        try {
            $this->pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT            => 5,
            ]);
            // Enable FK enforcement (off by default in SQLite)
            $this->pdo->exec('PRAGMA foreign_keys = ON');
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
        // SQLite has a single connection-level database called "main"
        // (plus any ATTACHed ones, which we don't expose here).
        return ['main'];
    }

    public function listTables(string $database): array
    {
        $this->ensureConnected();
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master
             WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
             ORDER BY name"
        );
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            // SQLite has no reliable row-count or size without a full scan.
            $out[] = ['name' => $row['name'], 'rows' => null, 'size' => null];
        }
        return $out;
    }

    public function browseTable(string $database, string $table, int $limit, int $offset): array
    {
        $this->ensureConnected();
        $this->validateIdent($table);
        $columns = $this->pragmaTableInfo($table);

        $qTbl   = $this->quoteIdent($table);
        $total  = (int)$this->pdo->query("SELECT COUNT(*) AS c FROM $qTbl")->fetch()['c'];
        $limit  = max(1, min(1000, $limit));
        $offset = max(0, $offset);
        $rstmt  = $this->pdo->query("SELECT * FROM $qTbl LIMIT $limit OFFSET $offset");
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
                $columns[] = ['name' => $meta['name'] ?? "col_$i", 'type' => $meta['sqlite:decl_type'] ?? 'TEXT'];
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
        $qTbl = $this->quoteIdent($table);
        $cols = implode(', ', array_map([$this, 'quoteIdent'], array_keys($values)));
        $phs  = implode(', ', array_fill(0, count($values), '?'));
        $stmt = $this->pdo->prepare("INSERT INTO $qTbl ($cols) VALUES ($phs)");
        $stmt->execute(array_values($values));
        return ['affected' => $stmt->rowCount(), 'insertId' => (int)$this->pdo->lastInsertId()];
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

        $stmt = $this->pdo->prepare(
            "UPDATE $qTbl SET " . implode(', ', $setClauses) . ' WHERE ' . implode(' AND ', $whereClauses)
        );
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

        $stmt = $this->pdo->prepare("DELETE FROM $qTbl WHERE " . implode(' AND ', $whereClauses));
        $stmt->execute($params);
        return ['affected' => $stmt->rowCount()];
    }

    public function addColumn(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($definition['name'] ?? '');
        $type = $this->sanitizeColumnType($definition['type'] ?? '');
        $null = ($definition['nullable'] ?? true) ? '' : ' NOT NULL';
        $def  = empty($definition['autoIncrement'])
                    ? (isset($definition['default']) && $definition['default'] !== null
                        ? ' DEFAULT ' . $this->pdo->quote((string)$definition['default'])
                        : '')
                    : '';
        // SQLite ADD COLUMN does not support AFTER or position hints.
        $this->pdo->exec("ALTER TABLE $qTbl ADD COLUMN $qCol $type$null$def");
    }

    public function modifyColumn(string $database, string $table, string $columnName, array $definition): void
    {
        throw new RuntimeException(
            'SQLite does not support column modification (rename/retype). '
            . 'To restructure a table, drop and recreate it.'
        );
    }

    public function dropColumn(string $database, string $table, string $columnName): void
    {
        $this->ensureConnected();
        if (!$this->sqliteVersionAtLeast('3.35.0')) {
            throw new RuntimeException(
                'DROP COLUMN requires SQLite 3.35.0 or later. '
                . 'Current version: ' . $this->sqliteVersion()
            );
        }
        $qTbl = $this->quoteIdent($table);
        $qCol = $this->quoteIdent($columnName);
        $this->pdo->exec("ALTER TABLE $qTbl DROP COLUMN $qCol");
    }

    public function reorderColumn(string $database, string $table, string $columnName, ?string $afterColumn): void
    {
        throw new RuntimeException(
            'SQLite does not support changing column positions. '
            . 'To reorder columns, drop and recreate the table.'
        );
    }

    public function describeTable(string $database, string $table): array
    {
        $this->ensureConnected();
        $columns = $this->pragmaTableInfo($table);
        if ($columns === []) {
            throw new RuntimeException("Table not found or not accessible: $table");
        }

        // Primary key column names
        $primaryKey = array_map(
            static fn($c) => $c['name'],
            array_filter($columns, static fn($c) => $c['key'] === 'primary')
        );

        // No dedicated index catalog — use PRAGMA index_list / index_info.
        $iListStmt = $this->pdo->prepare("PRAGMA index_list(" . $this->quoteIdent($table) . ")");
        $iListStmt->execute();
        $byIndex = [];
        foreach ($iListStmt->fetchAll() as $iRow) {
            $iInfoStmt = $this->pdo->query("PRAGMA index_info(" . $this->pdo->quote($iRow['name']) . ")");
            $cols = array_column($iInfoStmt->fetchAll(), 'name');
            $byIndex[] = [
                'name'    => $iRow['name'],
                'columns' => $cols,
                'unique'  => (bool)(int)$iRow['unique'],
            ];
        }

        return ['columns' => $columns, 'primaryKey' => array_values($primaryKey), 'indexes' => $byIndex];
    }

    public function getForeignKeys(string $database, string $table): array
    {
        $this->ensureConnected();
        $qTbl  = $this->quoteIdent($table);
        $stmt  = $this->pdo->query("PRAGMA foreign_key_list($qTbl)");
        $outgoing = [];
        foreach ($stmt->fetchAll() as $r) {
            $outgoing[] = [
                'column'             => $r['from'],
                'referencedDatabase' => 'main',
                'referencedTable'    => $r['table'],
                'referencedColumn'   => $r['to'],
                'constraintName'     => 'fk_' . $r['id'],
                'onUpdate'           => $r['on_update'],
                'onDelete'           => $r['on_delete'],
            ];
        }

        // SQLite PRAGMA doesn't give incoming FKs directly; skip for now.
        return ['outgoing' => $outgoing, 'incoming' => []];
    }

    public function sampleTable(string $database, string $table, int $limit): array
    {
        $this->ensureConnected();
        $this->validateIdent($table);
        $limit = max(1, min(20, $limit));
        $qTbl  = $this->quoteIdent($table);

        $stmt  = $this->pdo->query("SELECT * FROM $qTbl LIMIT $limit");
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
                "SELECT name FROM sqlite_master
                 WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
                   AND name LIKE :term ESCAPE '\\'
                 ORDER BY name LIMIT 100"
            );
            $stmt->execute([':term' => $like]);
            foreach ($stmt->fetchAll() as $r) {
                $tables[] = ['name' => $r['name'], 'comment' => ''];
            }
        }

        if ($scope === 'columns' || $scope === 'all') {
            // SQLite has no column-level search in the catalog; fall back to
            // iterating table_info for tables that have matching column names.
            $allTables = $this->pdo->query(
                "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name"
            )->fetchAll();
            foreach ($allTables as $tRow) {
                $tName = $tRow['name'];
                $cols  = $this->pragmaTableInfo($tName);
                foreach ($cols as $col) {
                    if (stripos($col['name'], $term) !== false) {
                        $columns[] = ['table' => $tName, 'name' => $col['name'], 'type' => $col['type'], 'comment' => ''];
                        if (count($columns) >= 200) break 2;
                    }
                }
            }
        }

        return ['tables' => $tables, 'columns' => $columns];
    }

    public function getCreateTable(string $database, string $table): string
    {
        $this->ensureConnected();
        $stmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = :tbl");
        $stmt->execute([':tbl' => $table]);
        $row = $stmt->fetch();
        if (!$row || !$row['sql']) {
            throw new RuntimeException("Table not found: $table");
        }
        return $row['sql'];
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
        $stmt = $this->pdo->query('EXPLAIN QUERY PLAN ' . $sql);
        return $stmt->fetchAll();
    }

    public function createIndex(string $database, string $table, array $definition): void
    {
        $this->ensureConnected();
        if (!empty($definition['primary'])) {
            throw new RuntimeException(
                'SQLite does not support adding a primary key after table creation. '
                . 'To change a primary key, recreate the table.'
            );
        }
        $this->validateIdent($definition['name']);
        $this->validateIdent($table);
        $qTbl = $this->quoteIdent($table);
        $qIdx = $this->quoteIdent($definition['name']);
        $cols = implode(', ', array_map([$this, 'quoteIdent'], $definition['columns']));
        $uniq = !empty($definition['unique']) ? 'UNIQUE ' : '';
        $this->pdo->exec("CREATE {$uniq}INDEX $qIdx ON $qTbl ($cols)");
    }

    public function dropIndex(string $database, string $table, string $indexName, bool $isPrimary): void
    {
        $this->ensureConnected();
        if ($isPrimary) {
            throw new RuntimeException(
                'SQLite does not support dropping a primary key without recreating the table.'
            );
        }
        $this->validateIdent($indexName);
        $qIdx = $this->quoteIdent($indexName);
        $this->pdo->exec("DROP INDEX $qIdx");
    }

    public function createForeignKey(string $database, string $table, array $definition): void
    {
        throw new RuntimeException(
            'SQLite does not support adding foreign key constraints after table creation. '
            . 'Foreign key constraints must be declared when creating the table.'
        );
    }

    public function dropForeignKey(string $database, string $table, string $constraintName): void
    {
        throw new RuntimeException(
            'SQLite does not support dropping foreign key constraints without recreating the table.'
        );
    }

    public function getDatabaseInfo(string $database): array
    {
        return [
            'name'      => $database,
            'charset'   => null,
            'collation' => null,
        ];
    }

    public function renameDatabase(string $database, string $newName): void
    {
        throw new RuntimeException('Renaming databases is not supported for SQLite.');
    }

    public function alterDatabaseCollation(string $database, string $collation): void
    {
        throw new RuntimeException('Changing database collation is not supported for SQLite.');
    }

    public function dropDatabase(string $database): void
    {
        throw new RuntimeException('Dropping databases is not supported for SQLite. Please delete the file manually.');
    }

    public function listDatabaseCollations(string $database): array
    {
        throw new RuntimeException('Listing database collations is not supported for SQLite');
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

    /**
     * Build column definitions from PRAGMA table_info().
     *
     * @return list<array{name:string,type:string,nullable:bool,key:'primary'|null,default:mixed,extra:string,autoIncrement:bool}>
     */
    private function pragmaTableInfo(string $table): array
    {
        $stmt = $this->pdo->query("PRAGMA table_info(" . $this->quoteIdent($table) . ")");
        $columns = [];
        foreach ($stmt->fetchAll() as $c) {
            $isPk          = (int)$c['pk'] > 0;
            $type          = strtoupper((string)$c['type']);
            // INTEGER PRIMARY KEY is SQLite's implicit ROWID alias (auto-increment-like)
            $isAutoInc     = $isPk && $type === 'INTEGER';
            $columns[] = [
                'name'          => $c['name'],
                'type'          => $type !== '' ? $type : 'TEXT',
                'nullable'      => !$c['notnull'] && !$isPk,
                'key'           => $isPk ? 'primary' : null,
                'default'       => $c['dflt_value'],
                'extra'         => $isAutoInc ? 'auto_increment' : '',
                'autoIncrement' => $isAutoInc,
            ];
        }
        return $columns;
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

    private function sqliteVersion(): string
    {
        return $this->pdo ? (string)$this->pdo->query('SELECT sqlite_version()')->fetchColumn() : '0.0.0';
    }

    private function sqliteVersionAtLeast(string $required): bool
    {
        return version_compare($this->sqliteVersion(), $required, '>=');
    }
}
