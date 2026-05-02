<?php declare(strict_types=1);

namespace Quermy\Drivers;

/**
 * Core contract every database driver must implement.
 *
 * Optional capabilities (column DDL, index management, views, stored objects,
 * database management, etc.) are declared as separate interfaces under
 * Quermy\Drivers\Capabilities\. Whether a driver supports a given capability
 * is determined by instanceof checks rather than boolean flags.
 *
 * CapabilitySerializer::serialize($driver) produces the JSON payload the
 * frontend expects, deriving all flags automatically from the driver's
 * implemented interfaces.
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
     * Execute one or more user-supplied statements.
     *
     * Returns one entry per result set produced. Non-SELECT statements
     * (INSERT, UPDATE, DELETE, DDL, …) produce a result with isSelect=false
     * and an empty columns/rows array. The durationMs on every entry reflects
     * the total wall-clock time for the whole batch; per-statement timing is
     * not available when the driver executes multiple statements in one call.
     *
     * @return list<array{
     *   columns: array<int,array{name:string,type:string}>,
     *   rows: array<int,array<string,mixed>>,
     *   affected: int,
     *   isSelect: bool,
     *   durationMs: float
     * }>
     */
    public function runQuery(string $database, string $sql): array;

    /**
     * @param array<string,mixed> $values  column → value
     * @return array{affected:int,insertId:int}
     */
    public function insertRow(string $database, string $table, array $values): array;

    /**
     * @param array<string,mixed> $where   column → value (AND-ed)
     * @param array<string,mixed> $values  column → new value
     * @return array{affected:int}
     */
    public function updateRow(string $database, string $table, array $where, array $values): array;

    /**
     * @param array<string,mixed> $where  column → value (AND-ed, must not be empty)
     * @return array{affected:int}
     */
    public function deleteRow(string $database, string $table, array $where): array;

    /** Identifier used when persisting connections (e.g. "mysql", "postgresql"). */
    public static function engineId(): string;

    /**
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
     * @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool}
     */
    public function sampleTable(string $database, string $table, int $limit): array;

    /**
     * @param string $scope One of "tables", "columns", "all".
     * @return array{
     *   tables: list<array{name:string,comment:string}>,
     *   columns: list<array{table:string,name:string,type:string,comment:string}>
     * }
     */
    public function searchSchema(string $database, string $term, string $scope): array;

    /**
     * Return metadata about a database: name, charset, collation.
     *
     * @return array{name:string, charset:string|null, collation:string|null}
     */
    public function getDatabaseInfo(string $database): array;
}
