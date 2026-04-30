<?php declare(strict_types=1);

namespace Quermy\Drivers;

/**
 * Contract that every database engine driver must implement.
 *
 * Adding a new engine (Postgres, SQLite, MSSQL...) is a matter of writing
 * one class that implements this interface and registering it in
 * DriverFactory. Nothing else in the codebase needs to change.
 */
interface DriverInterface
{
    /**
     * Open a connection. Throws on failure.
     *
     * @param array{host:string,port:int,username:string,password:string,database?:string} $config
     */
    public function connect(array $config): void;

    /** Close the connection (idempotent). */
    public function disconnect(): void;

    /** @return string[] List of database/schema names visible to this user. */
    public function listDatabases(): array;

    /** @return array<int,array{name:string,rows:int|null,size:int|null}> */
    public function listTables(string $database): array;

    /**
     * @return array{
     *   columns: array<int,array{name:string,type:string,nullable:bool,key:'primary'|'unique'|'index'|null,default:mixed,extra:string,autoIncrement:bool}>,
     *   rows: array<int,array<string,mixed>>,
     *   total: int
     * }
     */
    public function browseTable(string $database, string $table, int $limit, int $offset): array;

    /**
     * Execute an arbitrary user-supplied query.
     *
     * @return array{
     *   columns: array<int,array{name:string,type:string}>,
     *   rows: array<int,array<string,mixed>>,
     *   affected: int,
     *   isSelect: bool,
     *   durationMs: float
     * }
     */
    public function runQuery(string $database, string $sql): array;

    /**
     * Insert a single row.
     *
     * @param array<string,mixed> $values  column → value
     * @return array{affected:int,insertId:int}
     */
    public function insertRow(string $database, string $table, array $values): array;

    /**
     * Update rows matching $where.
     *
     * @param array<string,mixed> $where   column → value (AND-ed)
     * @param array<string,mixed> $values  column → new value
     * @return array{affected:int}
     */
    public function updateRow(string $database, string $table, array $where, array $values): array;

    /**
     * Delete rows matching $where.
     *
     * @param array<string,mixed> $where  column → value (AND-ed, must not be empty)
     * @return array{affected:int}
     */
    public function deleteRow(string $database, string $table, array $where): array;

    /**
     * Add a column to a table.
     *
     * @param array{name:string,type:string,nullable:bool,default:mixed,after?:string} $definition
     */
    public function addColumn(string $database, string $table, array $definition): void;

    /**
     * Rename / retype an existing column.
     *
     * @param array{name:string,type:string,nullable:bool,default:mixed} $definition
     */
    public function modifyColumn(string $database, string $table, string $columnName, array $definition): void;

    /** Drop a column from a table. */
    public function dropColumn(string $database, string $table, string $columnName): void;

    /**
     * Reorder a column by moving it immediately after $afterColumn,
     * or to the first position when $afterColumn is null.
     *
     * @throws \RuntimeException when the engine does not support reordering.
     */
    public function reorderColumn(string $database, string $table, string $columnName, ?string $afterColumn): void;

    /** Identifier used when persisting connections (e.g. "mysql", "postgresql"). */
    public static function engineId(): string;

    /**
     * Static metadata about this engine, available before any connection is
     * established. Used by the frontend to set sensible form defaults.
     *
     * @return array{
     *   id: string,
     *   label: string,
     *   defaultPort: int,
     *   defaultUsername: string,
     *   connectionType: 'tcp'|'file',
     *   identifierOpen: string,
     *   identifierClose: string,
     * }
     */
    public static function engineMeta(): array;

    /**
     * Runtime capabilities for the active connection. Used by the frontend to
     * adapt its UI (type suggestions, column-editing controls, etc.).
     *
     * - columnTypes:              suggested type strings for the column-type datalist
     * - supportsAutoIncrement:    engine has a native auto-increment / identity concept
     * - supportsColumnAfter:      ADD COLUMN … AFTER … is valid
     * - supportsModifyColumn:     column renames/retypes are supported
     * - supportsDropColumn:       DROP COLUMN is supported
     * - supportsReorderColumn:    column position can be changed
     * - supportsGetCreateTable:   getCreateTable() returns meaningful DDL
     * - supportsExplain:          explainQuery() is supported
     * - supportsForeignKeys:      getForeignKeys() returns data
     * - supportsIndexManagement:  createIndex()/dropIndex() for non-PK indexes
     * - supportsPrimaryKeyManagement: createIndex(primary)/dropIndex(isPrimary) supported
     * - supportsForeignKeyManagement: createForeignKey()/dropForeignKey() supported
     * - welcomeQuery:             default SQL shown when the user opens a new connection
     * - structureQueryTemplate:   default SQL for the "structure" view; use {db}/{table} tokens
     * - identifierOpen/Close:     quoting characters for identifiers (e.g. ` or ")
     *
     * @return array{
     *   columnTypes: list<string>,
     *   supportsAutoIncrement: bool,
     *   supportsColumnAfter: bool,
     *   supportsModifyColumn: bool,
     *   supportsDropColumn: bool,
     *   supportsReorderColumn: bool,
     *   supportsGetCreateTable: bool,
     *   supportsExplain: bool,
     *   supportsForeignKeys: bool,
     *   supportsIndexManagement: bool,
     *   supportsPrimaryKeyManagement: bool,
     *   supportsForeignKeyManagement: bool,
     *   welcomeQuery: string,
     *   structureQueryTemplate: string,
     *   identifierOpen: string,
     *   identifierClose: string,
     * }
     */
    public function getCapabilities(): array;

    /*
     * Schema-introspection methods used by the AI agent's tools.
     *
     * These let the agent understand a schema before suggesting queries:
     * column metadata, foreign keys, index coverage, raw DDL, sample data,
     * fuzzy schema search, and read-only EXPLAIN.
     */

    /**
     * Full structure of a single table.
     *
     * @return array{
     *   columns: list<array{name:string,type:string,nullable:bool,key:'primary'|'unique'|'index'|null,default:mixed,extra:string,comment:string,autoIncrement:bool}>,
     *   primaryKey: list<string>,
     *   indexes: list<array{name:string,columns:list<string>,unique:bool}>
     * }
     */
    public function describeTable(string $database, string $table): array;

    /**
     * Foreign key relationships involving a table — both the FKs declared
     * on this table (outgoing) and the FKs other tables declare against
     * this one (incoming).
     *
     * @return array{
     *   outgoing: list<array{column:string,referencedDatabase:string,referencedTable:string,referencedColumn:string,constraintName:string,onUpdate:string,onDelete:string}>,
     *   incoming: list<array{column:string,referencingDatabase:string,referencingTable:string,referencingColumn:string,constraintName:string,onUpdate:string,onDelete:string}>
     * }
     */
    public function getForeignKeys(string $database, string $table): array;

    /**
     * Create an index (including PRIMARY KEY or UNIQUE) on a table.
     *
     * @param array{name:string,columns:list<string>,unique:bool,primary:bool} $definition
     *   - primary: true  → add PRIMARY KEY (ignores name)
     *   - unique:  true  → CREATE UNIQUE INDEX
     *   - otherwise      → CREATE INDEX
     * @throws \RuntimeException when the engine does not support this operation.
     */
    public function createIndex(string $database, string $table, array $definition): void;

    /**
     * Drop an index or primary key from a table.
     *
     * @param bool $isPrimary When true, drops the PRIMARY KEY constraint rather
     *                        than a named index. $indexName is still passed so
     *                        engines that store the PK by its constraint name
     *                        (PostgreSQL, SQL Server) can use it directly.
     * @throws \RuntimeException when the engine does not support this operation.
     */
    public function dropIndex(string $database, string $table, string $indexName, bool $isPrimary): void;

    /**
     * Add a FOREIGN KEY constraint to a table.
     *
     * @param array{name:string,columns:list<string>,referencedTable:string,referencedColumns:list<string>,onUpdate:string,onDelete:string} $definition
     * @throws \RuntimeException when the engine does not support this operation.
     */
    public function createForeignKey(string $database, string $table, array $definition): void;

    /**
     * Drop a FOREIGN KEY constraint from a table.
     *
     * @throws \RuntimeException when the engine does not support this operation.
     */
    public function dropForeignKey(string $database, string $table, string $constraintName): void;

    /**
     * Read a small unordered sample of rows so the agent can see actual
     * data shape (enum values, date formats, JSON in TEXT columns, etc.).
     *
     * @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool}
     */
    public function sampleTable(string $database, string $table, int $limit): array;

    /**
     * Fuzzy substring search across table names, column names, and
     * (where supported) their comments.
     *
     * @param string $scope One of "tables", "columns", "all".
     *
     * @return array{
     *   tables: list<array{name:string,comment:string}>,
     *   columns: list<array{table:string,name:string,type:string,comment:string}>
     * }
     */
    public function searchSchema(string $database, string $term, string $scope): array;

    /**
     * Return the engine's authoritative CREATE TABLE statement for a table.
     */
    public function getCreateTable(string $database, string $table): string;

    /**
     * Run EXPLAIN on a read-only query and return the planner's output.
     * Implementations MUST refuse anything that is not a plain SELECT
     * (or a CTE feeding a SELECT) — this is part of the safety contract.
     *
     * @return list<array<string,mixed>>
     */
    public function explainQuery(string $database, string $sql): array;
}
