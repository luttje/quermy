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
     *   columns: array<int,array{name:string,type:string,nullable:bool,key:string,default:mixed}>,
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

    /** Identifier used when persisting connections. */
    public static function engineId(): string;

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
     *   columns: list<array{name:string,type:string,nullable:bool,key:string,default:mixed,extra:string,comment:string}>,
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
