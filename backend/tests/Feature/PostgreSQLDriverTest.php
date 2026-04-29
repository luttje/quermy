<?php declare(strict_types=1);

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
 * IDENTITY, JSONB, ALTER COLUMN, incoming foreign keys, EXPLAIN JSON).
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
        ->and($meta['defaultPort'])->toBe(5432)
        ->and($meta['identifierOpen'])->toBe('"')
        ->and($meta['connectionType'])->toBe('tcp');
});

/*
 * Shared contract
 */
it('exposes capabilities of the expected shape', fn() => $this->contract->capabilitiesShape());
it('lists databases including the target', fn() => $this->contract->listDatabasesIncludesTarget());
it('lists tables created in the database', fn() => $this->contract->listTablesShowsCreatedTables());
it('round-trips CRUD operations', fn() => $this->contract->crudRoundTrip());
it('refuses delete without a where clause', fn() => $this->contract->deleteRequiresWhere());
it('refuses update without where or values', fn() => $this->contract->updateRequiresWhereAndValues());
it('refuses insert without values', fn() => $this->contract->insertRequiresValues());
it('rejects malformed identifiers in browseTable', fn() => $this->contract->identifierValidationRejectsInjection());
it('describes a table with columns and primary key', fn() => $this->contract->describeTableReturnsColumns());
it('throws when describing an unknown table', fn() => $this->contract->describeUnknownTableThrows());
it('classifies SELECT vs non-SELECT in runQuery', fn() => $this->contract->runQueryClassifiesStatements());
it('adds and drops a column', fn() => $this->contract->addAndDropColumn());
it('samples rows up to the limit', fn() => $this->contract->sampleTableTruncates());
it('searches schema by table and column', fn() => $this->contract->searchSchemaFindsTablesAndColumns());
it('returns CREATE TABLE DDL', fn() => $this->contract->getCreateTableReturnsDdl());
it('explains SELECT and rejects other statement types', fn() => $this->contract->explainAcceptsSelectAndRejectsOther());
it('reports outgoing foreign keys on the child table', fn() => $this->contract->foreignKeysIntrospection());

/*
 * PostgreSQL-specific quirks
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

it('returns structured EXPLAIN JSON output', function () {
    $explain = $this->driver->explainQuery($this->database, 'SELECT 1 AS n');

    // PostgreSQL EXPLAIN (FORMAT JSON) is decoded to an array by the driver.
    expect($explain)->toBeArray()->not->toBeEmpty();
    // The top-level element should be the plan node array.
    expect($explain[0])->toBeArray();
});
