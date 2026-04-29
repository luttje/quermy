<?php declare(strict_types=1);

namespace Tests\Support;

use Quermy\Drivers\DriverInterface;
use RuntimeException;

/**
 * Behavioral contract that every driver must satisfy.
 *
 * Each engine's test file instantiates this with a connected driver, the
 * database name to operate on, and an EngineDialect telling the contract
 * how to quote identifiers and which types to use in CREATE TABLE.
 *
 * The contract creates and drops its own throwaway tables, so test order
 * doesn't matter and a failed run leaves the database queryable for
 * post-mortem inspection.
 *
 * Each method is a single coherent assertion sequence — Pest test files
 * call them one per `it()` block so failures are pinpointed precisely.
 */
final class DriverContract
{
    public function __construct(
        public readonly DriverInterface $driver,
        public readonly string $database,
        public readonly EngineDialect $dialect,
    ) {}

    private function q(string $ident): string
    {
        return $this->dialect->quote($ident);
    }

    private function int(): string
    {
        return $this->dialect->intType;
    }

    private function varchar(int $length): string
    {
        return $this->dialect->varchar($length);
    }

    public function listDatabasesIncludesTarget(): void
    {
        $databases = $this->driver->listDatabases();

        expect($databases)->toBeArray()
            ->and($databases)->toContain($this->database);
    }

    public function crudRoundTrip(): void
    {
        $table = 'crud_roundtrip';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (
                id  {$this->int()} PRIMARY KEY,
                tag {$this->varchar(50)} NOT NULL
            )"
        );

        $this->driver->insertRow($this->database, $table, ['id' => 1, 'tag' => 'alpha']);
        $this->driver->insertRow($this->database, $table, ['id' => 2, 'tag' => 'beta']);

        // Browse — sort the rows ourselves; without ORDER BY there is no
        // guarantee about row order.
        $browse = $this->driver->browseTable($this->database, $table, 100, 0);
        expect($browse['total'])->toBe(2)
            ->and($browse['rows'])->toHaveCount(2);

        $byId = [];
        foreach ($browse['rows'] as $row) {
            $byId[(int)$row['id']] = $row['tag'];
        }
        expect($byId)->toBe([1 => 'alpha', 2 => 'beta']);

        $upd = $this->driver->updateRow($this->database, $table, ['id' => 1], ['tag' => 'alpha-updated']);
        expect($upd['affected'])->toBe(1);

        $browse = $this->driver->browseTable($this->database, $table, 100, 0);
        $byId   = [];
        foreach ($browse['rows'] as $row) {
            $byId[(int)$row['id']] = $row['tag'];
        }
        expect($byId[1])->toBe('alpha-updated');

        $del = $this->driver->deleteRow($this->database, $table, ['id' => 2]);
        expect($del['affected'])->toBe(1);

        expect($this->driver->browseTable($this->database, $table, 100, 0)['total'])->toBe(1);
    }

    public function deleteRequiresWhere(): void
    {
        $table = 'delete_safety';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (id {$this->int()} PRIMARY KEY)"
        );

        expect(fn() => $this->driver->deleteRow($this->database, $table, []))
            ->toThrow(RuntimeException::class);
    }

    public function updateRequiresWhereAndValues(): void
    {
        $table = 'update_safety';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (id {$this->int()} PRIMARY KEY, name {$this->varchar(50)})"
        );

        expect(fn() => $this->driver->updateRow($this->database, $table, [], ['name' => 'x']))
            ->toThrow(RuntimeException::class);

        expect(fn() => $this->driver->updateRow($this->database, $table, ['id' => 1], []))
            ->toThrow(RuntimeException::class);
    }

    public function insertRequiresValues(): void
    {
        $table = 'insert_safety';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (id {$this->int()} PRIMARY KEY)"
        );

        expect(fn() => $this->driver->insertRow($this->database, $table, []))
            ->toThrow(RuntimeException::class);
    }

    public function identifierValidationRejectsInjection(): void
    {
        // A name containing punctuation + DROP TABLE shouldn't be reachable.
        expect(fn() => $this->driver->browseTable($this->database, 'evil; DROP TABLE x; --', 10, 0))
            ->toThrow(RuntimeException::class);

        expect(fn() => $this->driver->browseTable($this->database, 'a b', 10, 0))
            ->toThrow(RuntimeException::class);
    }

    public function listTablesShowsCreatedTables(): void
    {
        $table = 'list_tables_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (id {$this->int()} PRIMARY KEY)"
        );

        $names = array_column($this->driver->listTables($this->database), 'name');
        expect($names)->toContain($table);
    }

    public function describeTableReturnsColumns(): void
    {
        $table = 'describe_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (
                id    {$this->int()} PRIMARY KEY,
                name  {$this->varchar(100)} NOT NULL
            )"
        );

        $info = $this->driver->describeTable($this->database, $table);

        expect($info)->toHaveKeys(['columns', 'primaryKey', 'indexes'])
            ->and($info['columns'])->toHaveCount(2)
            ->and($info['primaryKey'])->toBe(['id']);

        $byName = [];
        foreach ($info['columns'] as $c) {
            $byName[$c['name']] = $c;
        }
        expect($byName['name']['nullable'])->toBeFalse();
    }

    public function describeUnknownTableThrows(): void
    {
        expect(fn() => $this->driver->describeTable($this->database, 'this_table_does_not_exist_xyz'))
            ->toThrow(RuntimeException::class);
    }

    public function runQueryClassifiesStatements(): void
    {
        $select = $this->driver->runQuery($this->database, 'SELECT 1 AS one');
        expect($select['isSelect'])->toBeTrue()
            ->and($select['rows'])->toHaveCount(1)
            ->and($select['durationMs'])->toBeFloat()->toBeGreaterThanOrEqual(0.0);

        $table = 'runquery_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $create = $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (id {$this->int()} PRIMARY KEY)"
        );
        expect($create['isSelect'])->toBeFalse();

        $insert = $this->driver->runQuery($this->database, "INSERT INTO {$this->q($table)} (id) VALUES (1)");
        expect($insert['isSelect'])->toBeFalse()
            ->and($insert['affected'])->toBe(1);
    }

    public function addAndDropColumn(): void
    {
        $table = 'addcol_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (id {$this->int()} PRIMARY KEY)"
        );

        $this->driver->addColumn($this->database, $table, [
            'name'     => 'note',
            'type'     => $this->varchar(64),
            'nullable' => true,
            'default'  => null,
        ]);

        $cols = array_column($this->driver->describeTable($this->database, $table)['columns'], 'name');
        expect($cols)->toContain('note');

        $this->driver->dropColumn($this->database, $table, 'note');

        $cols = array_column($this->driver->describeTable($this->database, $table)['columns'], 'name');
        expect($cols)->not->toContain('note');
    }

    public function sampleTableTruncates(): void
    {
        $table = 'sample_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (id {$this->int()} PRIMARY KEY)"
        );

        for ($i = 1; $i <= 5; $i++) {
            $this->driver->insertRow($this->database, $table, ['id' => $i]);
        }

        $sample = $this->driver->sampleTable($this->database, $table, 3);
        expect($sample['rows'])->toHaveCount(3)
            ->and($sample['truncated'])->toBeTrue()
            ->and($sample['columns'])->toContain('id');

        $sample = $this->driver->sampleTable($this->database, $table, 20);
        expect($sample['rows'])->toHaveCount(5)
            ->and($sample['truncated'])->toBeFalse();
    }

    public function searchSchemaFindsTablesAndColumns(): void
    {
        $table = 'searchable_widgets';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (
                id          {$this->int()} PRIMARY KEY,
                widget_code {$this->varchar(20)}
            )"
        );

        $byTable = $this->driver->searchSchema($this->database, 'widgets', 'tables');
        expect(array_column($byTable['tables'], 'name'))->toContain($table);

        $byColumn = $this->driver->searchSchema($this->database, 'widget_code', 'columns');
        $found = array_filter(
            $byColumn['columns'],
            fn($c) => $c['name'] === 'widget_code' && $c['table'] === $table
        );
        expect($found)->not->toBeEmpty();
    }

    public function getCreateTableReturnsDdl(): void
    {
        $table = 'ddl_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (id {$this->int()} PRIMARY KEY)"
        );

        $ddl = $this->driver->getCreateTable($this->database, $table);
        expect($ddl)->toBeString()
            ->and(strtolower($ddl))->toContain('create')
            ->and($ddl)->toContain($table);
    }

    public function explainAcceptsSelectAndRejectsOther(): void
    {
        $explain = $this->driver->explainQuery($this->database, 'SELECT 1');
        expect($explain)->toBeArray()->not->toBeEmpty();

        expect(fn() => $this->driver->explainQuery($this->database, 'DELETE FROM x'))
            ->toThrow(RuntimeException::class);

        expect(fn() => $this->driver->explainQuery($this->database, 'UPDATE x SET y = 1'))
            ->toThrow(RuntimeException::class);

        // Multi-statement chaining must be rejected.
        expect(fn() => $this->driver->explainQuery($this->database, 'SELECT 1; DROP TABLE x'))
            ->toThrow(RuntimeException::class);
    }

    public function capabilitiesShape(): void
    {
        $caps = $this->driver->getCapabilities();
        expect($caps)->toHaveKeys([
            'columnTypes',
            'supportsAutoIncrement',
            'supportsColumnAfter',
            'supportsModifyColumn',
            'supportsDropColumn',
            'supportsGetCreateTable',
            'supportsExplain',
            'supportsForeignKeys',
            'welcomeQuery',
            'structureQueryTemplate',
            'identifierOpen',
            'identifierClose',
        ])->and($caps['columnTypes'])->toBeArray()->not->toBeEmpty();
    }

    public function foreignKeysIntrospection(): void
    {
        $parent = 'fk_parent';
        $child  = 'fk_child';

        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($child)}");
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($parent)}");

        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($parent)} (
                id {$this->int()} PRIMARY KEY
            )"
        );
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($child)} (
                id        {$this->int()} PRIMARY KEY,
                parent_id {$this->int()},
                CONSTRAINT fk_child_parent
                    FOREIGN KEY (parent_id) REFERENCES {$this->q($parent)} (id)
            )"
        );

        $childFks = $this->driver->getForeignKeys($this->database, $child);
        expect($childFks['outgoing'])->not->toBeEmpty();

        $outgoing = $childFks['outgoing'][0];
        expect($outgoing['column'])->toBe('parent_id')
            ->and($outgoing['referencedTable'])->toBe($parent)
            ->and($outgoing['referencedColumn'])->toBe('id');
    }
}
