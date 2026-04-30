<?php declare(strict_types=1);

use Quermy\Drivers\CapabilitySerializer;
use Quermy\Drivers\DriverFactory;
use Quermy\Drivers\PostgreSQLDriver;
use Testcontainers\Modules\PostgresContainer;
use Tests\Support\BootedEngine;
use Tests\Support\ContainerSupport;
use Tests\Support\DriverContract;
use Tests\Support\EngineDialect;
use Testcontainers\Wait\WaitForLog;

/*
 * PostgreSQL driver feature tests.
 *
 * Boots a real PostgreSQL 16 container once per file, runs the shared
 * driver contract, plus PostgreSQL-specific checks (SERIAL / GENERATED AS
 * IDENTITY, JSONB, ALTER COLUMN, incoming foreign keys, EXPLAIN JSON,
 * index management, FK management, reorderColumn throwing).
 */

beforeAll(function () {
    ContainerSupport::skipIfNotInstalled(PostgresContainer::class, 'testcontainers/testcontainers');
    ContainerSupport::skipIfDockerUnavailable();

    $container = (new PostgresContainer('16'))
        ->withPostgresDatabase('quermy_test')
        ->withPostgresUser('quermy')
        ->withPostgresPassword('secret')
        ->withWait(new WaitForLog('database system is ready to accept connections', false, 60000))
        ->start();

    $driver = DriverFactory::make('postgresql');
    $driver->connect([
        'host'     => $container->getHost(),
        'port'     => (int)$container->getFirstMappedPort(),
        'username' => 'quermy',
        'password' => 'secret',
        'database' => 'quermy_test',
    ]);

    BootedEngine::set('postgresql', new BootedEngine($container, $driver, 'quermy_test'));
});

afterAll(function () {
    BootedEngine::tearDown('postgresql');
});

beforeEach(function () {
    $booted = BootedEngine::get('postgresql');

    if ($booted === null) {
        test()->markTestSkipped('PostgreSQL container is not available.');
    }

    /** @disregard */
    $this->driver = $booted->driver;

    /** @disregard */
    $this->database = $booted->database;

    /** @disregard */
    $this->contract = new DriverContract($this->driver, $this->database, EngineDialect::postgresql());
});

/*
 * Static metadata
 */
it('reports its engine id and metadata', function () {
    expect(PostgreSQLDriver::engineId())->toBe('postgresql');

    $meta = PostgreSQLDriver::engineMeta();
    expect($meta['id'])->toBe('postgresql')
        ->and($meta['label'])->toBe('PostgreSQL')
        ->and($meta['defaultPort'])->toBe(5432)
        ->and($meta['defaultUsername'])->not->toBeEmpty()
        ->and($meta['identifierOpen'])->toBe('"')
        ->and($meta['identifierClose'])->toBe('"')
        ->and($meta['connectionType'])->toBe('tcp');
});

/*
 * Shared contract — capabilities
 */
it('exposes capabilities of the expected shape', fn() => $this->contract->capabilitiesShape());

it('capabilities report PostgreSQL-appropriate flags', function () {
    $caps = CapabilitySerializer::serialize($this->driver);
    $engineMeta = $this->driver->engineMeta();

    // PostgreSQL uses SERIAL/IDENTITY instead of AUTO_INCREMENT.
    expect($caps['supportsAutoIncrement'])->toBeFalse()
        ->and($caps['supportsColumnAfter'])->toBeFalse()
        ->and($caps['supportsModifyColumn'])->toBeTrue()
        ->and($caps['supportsDropColumn'])->toBeTrue()
        ->and($caps['supportsReorderColumn'])->toBeFalse()
        ->and($caps['supportsGetCreateTable'])->toBeTrue()
        ->and($caps['supportsExplain'])->toBeTrue()
        ->and($caps['supportsForeignKeys'])->toBeTrue()
        ->and($caps['supportsIndexManagement'])->toBeTrue()
        ->and($caps['supportsForeignKeyManagement'])->toBeTrue()
        ->and($engineMeta['identifierOpen'])->toBe('"')
        ->and($engineMeta['identifierClose'])->toBe('"');
});

/*
 * Shared contract — databases & tables
 */
it('lists databases including the target', fn() => $this->contract->listDatabasesIncludesTarget());
it('lists tables created in the database', fn() => $this->contract->listTablesShowsCreatedTables());
it('listTables includes rows and size metadata', fn() => $this->contract->listTablesIncludesRowsAndSize());
it('manages views through the driver contract', fn() => $this->contract->viewManagementRoundTrip());

/*
 * Shared contract — CRUD
 */
it('round-trips CRUD operations', fn() => $this->contract->crudRoundTrip());
it('browseTable supports offset-based pagination', fn() => $this->contract->browseTablePagination());
it('refuses delete without a where clause', fn() => $this->contract->deleteRequiresWhere());
it('refuses update without where or values', fn() => $this->contract->updateRequiresWhereAndValues());
it('refuses insert without values', fn() => $this->contract->insertRequiresValues());
it('rejects malformed identifiers in browseTable', fn() => $this->contract->identifierValidationRejectsInjection());

/*
 * Shared contract — table description
 */
it('describes a table with columns and primary key', fn() => $this->contract->describeTableReturnsColumns());
it('describeTable column entries have all required keys', fn() => $this->contract->describeTableColumnShape());
it('throws when describing an unknown table', fn() => $this->contract->describeUnknownTableThrows());

/*
 * Shared contract — runQuery
 */
it('classifies SELECT vs non-SELECT in runQuery', fn() => $this->contract->runQueryClassifiesStatements());
it('runQuery result includes column metadata', fn() => $this->contract->runQueryReturnsColumnMeta());

/*
 * Shared contract — column DDL
 */
it('adds and drops a column', fn() => $this->contract->addAndDropColumn());
it('adds a nullable column with a default value', fn() => $this->contract->addNullableColumnWithDefault());

/*
 * Shared contract — sampling & search
 */
it('samples rows up to the limit', fn() => $this->contract->sampleTableTruncates());
it('searches schema by table and column', fn() => $this->contract->searchSchemaFindsTablesAndColumns());
it('searchSchema "all" scope returns both tables and columns', fn() => $this->contract->searchSchemaAllScopeReturnsBoth());
it('searchSchema returns empty arrays when nothing matches', fn() => $this->contract->searchSchemaReturnsEmptyForNoMatch());

/*
 * Shared contract — DDL introspection & EXPLAIN
 */
it('returns CREATE TABLE DDL', fn() => $this->contract->getCreateTableReturnsDdl());
it('explains SELECT and rejects other statement types', fn() => $this->contract->explainAcceptsSelectAndRejectsOther());

/*
 * Shared contract — foreign key introspection
 */
it('reports outgoing foreign keys on the child table', fn() => $this->contract->foreignKeysIntrospection());
it('outgoing FK entries have all required keys', fn() => $this->contract->foreignKeysOutgoingShape());

/*
 * Shared contract — index management
 */
it('creates and drops a regular index', fn() => $this->contract->createAndDropRegularIndex());
it('creates and drops a unique index', fn() => $this->contract->createAndDropUniqueIndex());
it('creates a composite index on multiple columns', fn() => $this->contract->createCompositeIndex());

/*
 * Shared contract — foreign key management
 */
it('creates and drops a foreign key constraint', fn() => $this->contract->createAndDropForeignKeyConstraint());

/*
 * PostgreSQL-specific: incoming foreign keys
 */
it('reports incoming foreign keys on the parent table', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "fk_child_in"');
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "fk_parent_in"');
    $this->driver->runQuery($this->database, 'CREATE TABLE "fk_parent_in" (id INTEGER PRIMARY KEY)');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE "fk_child_in" (
            id        INTEGER PRIMARY KEY,
            parent_id INTEGER,
            CONSTRAINT fk_in FOREIGN KEY (parent_id) REFERENCES "fk_parent_in" (id)
        )'
    );

    $parentFks = $this->driver->getForeignKeys($this->database, 'fk_parent_in');
    expect($parentFks['incoming'])->not->toBeEmpty()
        ->and($parentFks['incoming'][0]['referencingTable'])->toBe('fk_child_in');
});

it('incoming FK entries have all required keys', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "fk_shape_child_in"');
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "fk_shape_parent_in"');
    $this->driver->runQuery($this->database, 'CREATE TABLE "fk_shape_parent_in" (id INTEGER PRIMARY KEY)');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE "fk_shape_child_in" (
            id        INTEGER PRIMARY KEY,
            parent_id INTEGER,
            CONSTRAINT fk_shape_in FOREIGN KEY (parent_id) REFERENCES "fk_shape_parent_in" (id)
        )'
    );

    $fks = $this->driver->getForeignKeys($this->database, 'fk_shape_parent_in');
    expect($fks['incoming'])->not->toBeEmpty();

    $inc = $fks['incoming'][0];
    expect($inc)->toHaveKeys([
        'column',
        'referencingDatabase',
        'referencingTable',
        'referencingColumn',
        'constraintName',
        'onUpdate',
        'onDelete',
    ]);
});

/*
 * PostgreSQL-specific: SERIAL auto-increment
 */
it('handles SERIAL and reports the insert id', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "serial_probe"');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE "serial_probe" (
            id   SERIAL PRIMARY KEY,
            name VARCHAR(50)
         )'
    );

    $first  = $this->driver->insertRow($this->database, 'serial_probe', ['name' => 'a']);
    $second = $this->driver->insertRow($this->database, 'serial_probe', ['name' => 'b']);

    expect($first['insertId'])->toBe(1)
        ->and($second['insertId'])->toBe(2);
});

it('handles GENERATED AS IDENTITY and reports the insert id', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "identity_probe"');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE "identity_probe" (
            id   INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
            name VARCHAR(50)
         )'
    );

    $first  = $this->driver->insertRow($this->database, 'identity_probe', ['name' => 'x']);
    $second = $this->driver->insertRow($this->database, 'identity_probe', ['name' => 'y']);

    expect($first['insertId'])->toBe(1)
        ->and($second['insertId'])->toBe(2);
});

it('describeTable marks SERIAL/identity column as autoIncrement=true', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "autoinc_desc_probe"');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE "autoinc_desc_probe" (
            id   SERIAL PRIMARY KEY,
            name VARCHAR(50)
         )'
    );

    $info   = $this->driver->describeTable($this->database, 'autoinc_desc_probe');
    $byName = array_column($info['columns'], null, 'name');

    expect($byName['id']['autoIncrement'])->toBeTrue()
        ->and($byName['name']['autoIncrement'])->toBeFalse();
});

/*
 * PostgreSQL-specific: modifyColumn (retype only — PG does not support rename in same call)
 */
it('modifies a column in place (retype)', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "modify_probe"');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE "modify_probe" (id INTEGER PRIMARY KEY, note VARCHAR(20))'
    );

    $this->driver->modifyColumn($this->database, 'modify_probe', 'note', [
        'name'     => 'note',
        'type'     => 'TEXT',
        'nullable' => true,
        'default'  => null,
    ]);

    $cols   = $this->driver->describeTable($this->database, 'modify_probe')['columns'];
    $byName = [];
    foreach ($cols as $c) { $byName[$c['name']] = $c; }

    expect($byName)->toHaveKey('note')
        ->and(strtoupper($byName['note']['type']))->toContain('TEXT');
});

/*
 * PostgreSQL-specific: PRIMARY KEY management
 */
it('creates and drops a primary key constraint', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "pk_mgmt_probe"');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE "pk_mgmt_probe" (id INTEGER NOT NULL, name VARCHAR(50))'
    );

    $this->driver->createIndex($this->database, 'pk_mgmt_probe', [
        'name'    => 'pk_mgmt_probe_pkey',
        'columns' => ['id'],
        'unique'  => false,
        'primary' => true,
    ]);

    $info = $this->driver->describeTable($this->database, 'pk_mgmt_probe');
    expect($info['primaryKey'])->toBe(['id']);

    // Drop by constraint name (PostgreSQL uses the constraint name, not "PRIMARY").
    $this->driver->dropIndex($this->database, 'pk_mgmt_probe', 'pk_mgmt_probe_pkey', true);

    $info = $this->driver->describeTable($this->database, 'pk_mgmt_probe');
    expect($info['primaryKey'])->toBeEmpty();
});

/*
 * PostgreSQL-specific: JSONB round-trip
 */
it('roundtrips JSONB columns', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "jsonb_probe"');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE "jsonb_probe" (id INTEGER PRIMARY KEY, payload JSONB)'
    );

    $payload = json_encode(['hello' => 'world', 'n' => 42]);
    $this->driver->insertRow($this->database, 'jsonb_probe', ['id' => 1, 'payload' => $payload]);

    $row = $this->driver->browseTable($this->database, 'jsonb_probe', 10, 0)['rows'][0];
    expect(json_decode($row['payload'], true))->toEqual(['hello' => 'world', 'n' => 42]);
});

/*
 * PostgreSQL-specific: EXPLAIN JSON format
 */
it('returns structured EXPLAIN JSON output', function () {
    $explain = $this->driver->explainQuery($this->database, 'SELECT 1 AS n');

    // PostgreSQL EXPLAIN (FORMAT JSON) is decoded to an array by the driver.
    expect($explain)->toBeArray()->not->toBeEmpty();
    // The top-level element should be the plan node array.
    expect($explain[0])->toBeArray();
});
