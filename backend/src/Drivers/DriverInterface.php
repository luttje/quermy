<?php
declare(strict_types=1);

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

    /** Identifier used when persisting connections. */
    public static function engineId(): string;
}
