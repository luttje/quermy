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

    // -------------------------------------------------------------------------
    // Basic introspection
    // -------------------------------------------------------------------------

    public function listDatabasesIncludesTarget(): void
    {
        $databases = $this->driver->listDatabases();

        expect($databases)->toBeArray()
            ->and($databases)->toContain($this->database);
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

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

    public function browseTablePagination(): void
    {
        $table = 'browse_pagination';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (id {$this->int()} PRIMARY KEY)"
        );

        for ($i = 1; $i <= 10; $i++) {
            $this->driver->insertRow($this->database, $table, ['id' => $i]);
        }

        $page1 = $this->driver->browseTable($this->database, $table, 4, 0);
        $page2 = $this->driver->browseTable($this->database, $table, 4, 4);
        $page3 = $this->driver->browseTable($this->database, $table, 4, 8);

        expect($page1['total'])->toBe(10)
            ->and($page1['rows'])->toHaveCount(4)
            ->and($page2['rows'])->toHaveCount(4)
            ->and($page3['rows'])->toHaveCount(2);
    }

    // -------------------------------------------------------------------------
    // Safety guards
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Table listing & description
    // -------------------------------------------------------------------------

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

    public function listTablesIncludesRowsAndSize(): void
    {
        $table = 'list_tables_meta';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (id {$this->int()} PRIMARY KEY)"
        );
        $this->driver->insertRow($this->database, $table, ['id' => 1]);

        $tables = $this->driver->listTables($this->database);
        $entry  = null;
        foreach ($tables as $t) {
            if ($t['name'] === $table) {
                $entry = $t;
                break;
            }
        }

        expect($entry)->not->toBeNull()
            ->and($entry)->toHaveKeys(['name', 'rows', 'size']);

        // rows/size may legitimately be null for some engines, but when
        // not null they should be non-negative integers.
        if ($entry['rows'] !== null) {
            expect($entry['rows'])->toBeInt()->toBeGreaterThanOrEqual(0);
        }
        if ($entry['size'] !== null) {
            expect($entry['size'])->toBeInt()->toBeGreaterThanOrEqual(0);
        }
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

    public function describeTableColumnShape(): void
    {
        $table = 'describe_shape_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (
                id    {$this->int()} PRIMARY KEY,
                label {$this->varchar(80)} NOT NULL
            )"
        );

        $info = $this->driver->describeTable($this->database, $table);

        $requiredKeys = ['name', 'type', 'nullable', 'key', 'default', 'extra', 'autoIncrement'];
        if ($this->dialect->supportsColumnComments) {
            $requiredKeys[] = 'comment';
        }

        foreach ($info['columns'] as $col) {
            expect($col)->toHaveKeys($requiredKeys);
            expect($col['nullable'])->toBeBool();
            expect($col['autoIncrement'])->toBeBool();
        }

        $byName = [];
        foreach ($info['columns'] as $c) {
            $byName[$c['name']] = $c;
        }
        expect($byName['id']['key'])->toBe('primary');
    }

    public function describeUnknownTableThrows(): void
    {
        expect(fn() => $this->driver->describeTable($this->database, 'this_table_does_not_exist_xyz'))
            ->toThrow(RuntimeException::class);
    }

    // -------------------------------------------------------------------------
    // runQuery
    // -------------------------------------------------------------------------

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

    public function runQueryReturnsColumnMeta(): void
    {
        $result = $this->driver->runQuery($this->database, 'SELECT 1 AS one');
        expect($result)->toHaveKey('columns')
            ->and($result['columns'])->toBeArray()
            ->and($result['columns'])->not->toBeEmpty();

        $col = $result['columns'][0];
        expect($col)->toHaveKeys(['name', 'type'])
            ->and($col['name'])->toBe('one');
    }

    // -------------------------------------------------------------------------
    // Column DDL
    // -------------------------------------------------------------------------

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

    public function addNullableColumnWithDefault(): void
    {
        $table = 'addcol_default_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (id {$this->int()} PRIMARY KEY)"
        );

        $this->driver->addColumn($this->database, $table, [
            'name'     => 'score',
            'type'     => $this->int(),
            'nullable' => true,
            'default'  => '0',
        ]);

        $info   = $this->driver->describeTable($this->database, $table);
        $byName = array_column($info['columns'], null, 'name');

        expect($byName)->toHaveKey('score')
            ->and($byName['score']['nullable'])->toBeTrue();
    }

    // -------------------------------------------------------------------------
    // Sampling
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Schema search
    // -------------------------------------------------------------------------

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

    public function searchSchemaAllScopeReturnsBoth(): void
    {
        $table = 'search_all_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (
                id          {$this->int()} PRIMARY KEY,
                search_col  {$this->varchar(20)}
            )"
        );

        $result = $this->driver->searchSchema($this->database, 'search_all_probe', 'all');

        expect($result)->toHaveKeys(['tables', 'columns'])
            ->and(array_column($result['tables'], 'name'))->toContain($table);
    }

    public function searchSchemaReturnsEmptyForNoMatch(): void
    {
        $result = $this->driver->searchSchema($this->database, 'zzz_no_such_thing_xyz_999', 'all');

        expect($result['tables'])->toBeArray()->toBeEmpty()
            ->and($result['columns'])->toBeArray()->toBeEmpty();
    }

    // -------------------------------------------------------------------------
    // DDL introspection
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // EXPLAIN
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Capabilities
    // -------------------------------------------------------------------------

    public function capabilitiesShape(): void
    {
        $caps = $this->driver->getCapabilities();
        expect($caps)->toHaveKeys([
            'columnTypes',
            'supportsAutoIncrement',
            'supportsColumnAfter',
            'supportsModifyColumn',
            'supportsDropColumn',
            'supportsReorderColumn',
            'supportsGetCreateTable',
            'supportsExplain',
            'supportsForeignKeys',
            'supportsIndexManagement',
            'supportsPrimaryKeyManagement',
            'supportsForeignKeyManagement',
            'welcomeQuery',
            'structureQueryTemplate',
            'identifierOpen',
            'identifierClose',
        ])->and($caps['columnTypes'])->toBeArray()->not->toBeEmpty();

        // All boolean flags must actually be booleans.
        foreach ([
            'supportsAutoIncrement',
            'supportsColumnAfter',
            'supportsModifyColumn',
            'supportsDropColumn',
            'supportsReorderColumn',
            'supportsGetCreateTable',
            'supportsExplain',
            'supportsForeignKeys',
            'supportsIndexManagement',
            'supportsPrimaryKeyManagement',
            'supportsForeignKeyManagement',
        ] as $flag) {
            expect($caps[$flag])->toBeBool("$flag must be a bool");
        }

        // String fields must be non-empty strings.
        foreach (['welcomeQuery', 'structureQueryTemplate', 'identifierOpen', 'identifierClose'] as $key) {
            expect($caps[$key])->toBeString()->not->toBeEmpty();
        }
    }

    // -------------------------------------------------------------------------
    // Foreign keys introspection
    // -------------------------------------------------------------------------

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

    public function foreignKeysOutgoingShape(): void
    {
        $parent = 'fk_shape_parent';
        $child  = 'fk_shape_child';

        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($child)}");
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($parent)}");

        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($parent)} (id {$this->int()} PRIMARY KEY)"
        );
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($child)} (
                id        {$this->int()} PRIMARY KEY,
                parent_id {$this->int()},
                CONSTRAINT fk_shape_con
                    FOREIGN KEY (parent_id) REFERENCES {$this->q($parent)} (id)
            )"
        );

        $fks = $this->driver->getForeignKeys($this->database, $child);

        expect($fks)->toHaveKeys(['outgoing', 'incoming']);

        $out = $fks['outgoing'][0];
        expect($out)->toHaveKeys([
            'column',
            'referencedDatabase',
            'referencedTable',
            'referencedColumn',
            'constraintName',
            'onUpdate',
            'onDelete',
        ]);
    }

    // -------------------------------------------------------------------------
    // Index management
    // -------------------------------------------------------------------------

    public function createAndDropRegularIndex(): void
    {
        $table = 'idx_regular_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (
                id   {$this->int()} PRIMARY KEY,
                name {$this->varchar(80)}
            )"
        );

        $this->driver->createIndex($this->database, $table, [
            'name'    => 'idx_regular_name',
            'columns' => ['name'],
            'unique'  => false,
            'primary' => false,
        ]);

        $info    = $this->driver->describeTable($this->database, $table);
        $idxNames = array_column($info['indexes'], 'name');
        expect($idxNames)->toContain('idx_regular_name');

        $this->driver->dropIndex($this->database, $table, 'idx_regular_name', false);

        $info    = $this->driver->describeTable($this->database, $table);
        $idxNames = array_column($info['indexes'], 'name');
        expect($idxNames)->not->toContain('idx_regular_name');
    }

    public function createAndDropUniqueIndex(): void
    {
        $table = 'idx_unique_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (
                id    {$this->int()} PRIMARY KEY,
                email {$this->varchar(120)}
            )"
        );

        $this->driver->createIndex($this->database, $table, [
            'name'    => 'idx_unique_email',
            'columns' => ['email'],
            'unique'  => true,
            'primary' => false,
        ]);

        $info = $this->driver->describeTable($this->database, $table);
        $byName = array_column($info['indexes'], null, 'name');
        expect($byName)->toHaveKey('idx_unique_email')
            ->and($byName['idx_unique_email']['unique'])->toBeTrue();

        $this->driver->dropIndex($this->database, $table, 'idx_unique_email', false);

        $info = $this->driver->describeTable($this->database, $table);
        expect(array_column($info['indexes'], 'name'))->not->toContain('idx_unique_email');
    }

    public function createCompositeIndex(): void
    {
        $table = 'idx_composite_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (
                id    {$this->int()} PRIMARY KEY,
                first {$this->varchar(60)},
                last  {$this->varchar(60)}
            )"
        );

        $this->driver->createIndex($this->database, $table, [
            'name'    => 'idx_composite_name',
            'columns' => ['first', 'last'],
            'unique'  => false,
            'primary' => false,
        ]);

        $info   = $this->driver->describeTable($this->database, $table);
        $byName = array_column($info['indexes'], null, 'name');
        expect($byName)->toHaveKey('idx_composite_name')
            ->and($byName['idx_composite_name']['columns'])->toBe(['first', 'last']);
    }

    // -------------------------------------------------------------------------
    // Foreign key management (create / drop)
    // -------------------------------------------------------------------------

    public function createAndDropForeignKeyConstraint(): void
    {
        $parent = 'fkm_parent';
        $child  = 'fkm_child';

        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($child)}");
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($parent)}");

        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($parent)} (id {$this->int()} PRIMARY KEY)"
        );
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($child)} (
                id        {$this->int()} PRIMARY KEY,
                parent_id {$this->int()}
            )"
        );

        $this->driver->createForeignKey($this->database, $child, [
            'name'              => 'fkm_con',
            'columns'           => ['parent_id'],
            'referencedTable'   => $parent,
            'referencedColumns' => ['id'],
            'onUpdate'          => 'RESTRICT',
            'onDelete'          => 'CASCADE',
        ]);

        $fks = $this->driver->getForeignKeys($this->database, $child);
        $names = array_column($fks['outgoing'], 'constraintName');
        expect($names)->toContain('fkm_con');

        $this->driver->dropForeignKey($this->database, $child, 'fkm_con');

        $fks   = $this->driver->getForeignKeys($this->database, $child);
        $names = array_column($fks['outgoing'], 'constraintName');
        expect($names)->not->toContain('fkm_con');
    }

    // -------------------------------------------------------------------------
    // reorderColumn (engines that support it)
    // -------------------------------------------------------------------------

    public function reorderColumnMovesToFirst(): void
    {
        $table = 'reorder_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (
                id    {$this->int()} PRIMARY KEY,
                alpha {$this->varchar(20)},
                beta  {$this->varchar(20)}
            )"
        );

        // Move 'beta' to first position.
        $this->driver->reorderColumn($this->database, $table, 'beta', null);

        $cols = array_column($this->driver->describeTable($this->database, $table)['columns'], 'name');
        expect($cols[0])->toBe('beta');
    }

    public function reorderColumnMovesAfterTarget(): void
    {
        $table = 'reorder_after_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (
                id    {$this->int()} PRIMARY KEY,
                alpha {$this->varchar(20)},
                beta  {$this->varchar(20)}
            )"
        );

        // Move 'id' to after 'alpha' → expected order: alpha, id, beta
        $this->driver->reorderColumn($this->database, $table, 'id', 'alpha');

        $cols = array_column($this->driver->describeTable($this->database, $table)['columns'], 'name');
        $pos  = array_flip($cols);
        expect($pos['id'])->toBeGreaterThan($pos['alpha'])
            ->and($pos['id'])->toBeLessThan($pos['beta']);
    }

    // -------------------------------------------------------------------------
    // reorderColumn unsupported engines — must throw
    // -------------------------------------------------------------------------

    public function reorderColumnThrowsWhenUnsupported(): void
    {
        $table = 'reorder_unsupported_probe';
        $this->driver->runQuery($this->database, "DROP TABLE IF EXISTS {$this->q($table)}");
        $this->driver->runQuery(
            $this->database,
            "CREATE TABLE {$this->q($table)} (
                id   {$this->int()} PRIMARY KEY,
                name {$this->varchar(20)}
            )"
        );

        expect(fn() => $this->driver->reorderColumn($this->database, $table, 'name', null))
            ->toThrow(RuntimeException::class);
    }
}
