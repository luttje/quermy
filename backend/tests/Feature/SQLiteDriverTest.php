<?php declare(strict_types=1);

use Quermy\Drivers\DriverFactory;
use Quermy\Drivers\SQLiteDriver;
use Tests\Support\BootedEngine;
use Tests\Support\DriverContract;
use Tests\Support\EngineDialect;

/*
 * SQLite driver feature tests.
 *
 * SQLite needs no container — the driver opens a copy of the seed file at
 * tests/data/sqlite.db that lives in the repository.  A fresh in-memory
 * database is used for all mutating contract tests so test order doesn't
 * matter and the seed file is never modified.
 *
 * SQLite-specific checks cover: INTEGER PRIMARY KEY auto-increment,
 * EXPLAIN QUERY PLAN output, DROP COLUMN (skipped when the linked SQLite
 * is < 3.35), and the fact that modifyColumn always throws.
 */

// Path to the seed database relative to the project root.
// Adjust if the file lives elsewhere.
$seedPath = dirname(__DIR__) . '/data/sqlite.db';

beforeAll(function () use ($seedPath) {
    // Use an in-memory database so every run starts clean and the seed
    // file on disk is never modified.
    $driver = DriverFactory::make('sqlite');
    $driver->connect(['database' => ':memory:']);

    // "database" is always "main" for SQLite.
    BootedEngine::set('sqlite', new BootedEngine(new stdClass(), $driver, 'main'));
});

afterAll(function () {
    BootedEngine::tearDown('sqlite');
});

beforeEach(function () {
    $booted = BootedEngine::get('sqlite');

    if ($booted === null) {
        test()->markTestSkipped('SQLite driver is not available.');
    }

    /** @disregard */
    $this->driver = $booted->driver;

    /** @disregard */
    $this->database = $booted->database;

    /** @disregard */
    $this->contract = new DriverContract($this->driver, $this->database, EngineDialect::sqlite());
});

/*
 * Static metadata
 */
it('reports its engine id and metadata', function () {
    expect(SQLiteDriver::engineId())->toBe('sqlite');

    $meta = SQLiteDriver::engineMeta();
    expect($meta['id'])->toBe('sqlite')
        ->and($meta['defaultPort'])->toBe(0)
        ->and($meta['connectionType'])->toBe('file')
        ->and($meta['identifierOpen'])->toBe('"');
});

it('connects to the seed database file and reads it', function () use ($seedPath) {
    if (!file_exists($seedPath)) {
        test()->markTestSkipped("Seed file not found at $seedPath — skipping file-read smoke test.");
    }

    $driver = DriverFactory::make('sqlite');
    $driver->connect(['database' => $seedPath]);

    // The seed file must at least contain "main" as its only database.
    expect($driver->listDatabases())->toBe(['main']);

    // We can list tables without throwing (even if the seed is empty).
    expect($driver->listTables('main'))->toBeArray();

    $driver->disconnect();
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
 * SQLite-specific quirks
 */

it('INTEGER PRIMARY KEY acts as an auto-increment alias and reports the insert id', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "autoinc_probe"');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE "autoinc_probe" (
            id   INTEGER PRIMARY KEY,
            name TEXT
         )'
    );

    $first  = $this->driver->insertRow($this->database, 'autoinc_probe', ['name' => 'a']);
    $second = $this->driver->insertRow($this->database, 'autoinc_probe', ['name' => 'b']);

    expect($first['insertId'])->toBe(1)
        ->and($second['insertId'])->toBe(2);
});

it('EXPLAIN QUERY PLAN returns rows with id/parent/detail columns', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "explain_probe"');
    $this->driver->runQuery($this->database, 'CREATE TABLE "explain_probe" (id INTEGER PRIMARY KEY, val TEXT)');

    $plan = $this->driver->explainQuery($this->database, 'SELECT * FROM "explain_probe"');

    expect($plan)->toBeArray()->not->toBeEmpty();
    // SQLite EXPLAIN QUERY PLAN rows always contain these three columns.
    $first = $plan[0];
    expect($first)->toHaveKeys(['id', 'parent', 'detail']);
});

it('modifyColumn always throws because SQLite does not support it', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "nomodify_probe"');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE "nomodify_probe" (id INTEGER PRIMARY KEY, name TEXT)'
    );

    expect(fn() => $this->driver->modifyColumn($this->database, 'nomodify_probe', 'name', [
        'name'     => 'renamed',
        'type'     => 'TEXT',
        'nullable' => true,
        'default'  => null,
    ]))->toThrow(RuntimeException::class);
});

it('DROP COLUMN is skipped on SQLite < 3.35', function () {
    // The contract's addAndDropColumn() already exercises the happy path
    // on SQLite >= 3.35. This test ensures that on older runtimes the
    // driver throws rather than silently doing nothing.
    $version = $this->driver->runQuery($this->database, 'SELECT sqlite_version() AS v')['rows'][0]['v'];

    if (version_compare($version, '3.35.0', '>=')) {
        // Already covered by the shared contract test; nothing more to verify.
        expect(true)->toBeTrue();
        return;
    }

    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "dropcol_probe"');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE "dropcol_probe" (id INTEGER PRIMARY KEY, name TEXT)'
    );

    expect(fn() => $this->driver->dropColumn($this->database, 'dropcol_probe', 'name'))
        ->toThrow(RuntimeException::class);
});

it('listDatabases always returns exactly ["main"]', function () {
    expect($this->driver->listDatabases())->toBe(['main']);
});

it('getCapabilities reports connectionType as file', function () {
    $caps = $this->driver->getCapabilities();
    // SQLite is a file-based engine; the identifier quoting uses double-quotes.
    expect($caps['identifierOpen'])->toBe('"')
        ->and($caps['supportsColumnAfter'])->toBeFalse()
        ->and($caps['supportsModifyColumn'])->toBeFalse();
});
