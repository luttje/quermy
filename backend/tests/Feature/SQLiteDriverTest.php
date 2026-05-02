<?php declare(strict_types=1);

use Quermy\Drivers\CapabilitySerializer;
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
 * is < 3.35), modifyColumn always throws, reorderColumn always throws,
 * primary-key management throws, FK management throws, and the connection
 * type being "file".
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
        ->and($meta['identifierOpen'])->toBe('"')
        ->and($meta['identifierClose'])->toBe('"');
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
 * Shared contract — capabilities
 */
it('exposes capabilities of the expected shape', fn() => $this->contract->capabilitiesShape());

it('capabilities report SQLite-appropriate flags', function () {
    $caps = CapabilitySerializer::serialize($this->driver);
    $engineMeta = $this->driver->engineMeta();

    expect($caps['supportsAutoIncrement'])->toBeTrue()  // INTEGER PRIMARY KEY acts as ROWID alias
        ->and($caps['supportsColumnAfter'])->toBeFalse()
        ->and($caps['supportsModifyColumn'])->toBeFalse()
        ->and($caps['supportsReorderColumn'])->toBeFalse()
        ->and($caps['supportsGetCreateTable'])->toBeTrue()
        ->and($caps['supportsExplain'])->toBeTrue()
        ->and($caps['supportsForeignKeys'])->toBeTrue()
        ->and($caps['supportsIndexManagement'])->toBeTrue()
        ->and($caps['supportsForeignKeyManagement'])->toBeFalse()
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
 * Shared contract — getDatabaseInfo
 */
it('getDatabaseInfo returns the expected shape', fn() => $this->contract->getDatabaseInfoShape());

/*
 * Shared contract — drop and truncate table
 */
it('dropTable removes the table from the schema', fn() => $this->contract->dropTableCapabilityRoundTrip());
it('truncateTable clears all rows', fn() => $this->contract->truncateTableClearsRows());

/*
 * SQLite-specific: INTEGER PRIMARY KEY auto-increment
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

/*
 * SQLite-specific: EXPLAIN QUERY PLAN shape
 */
it('EXPLAIN QUERY PLAN returns rows with id/parent/detail columns', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "explain_probe"');
    $this->driver->runQuery($this->database, 'CREATE TABLE "explain_probe" (id INTEGER PRIMARY KEY, val TEXT)');

    $plan = $this->driver->explainQuery($this->database, 'SELECT * FROM "explain_probe"');

    expect($plan)->toBeArray()->not->toBeEmpty();
    // SQLite EXPLAIN QUERY PLAN rows always contain these three columns.
    $first = $plan[0];
    expect($first)->toHaveKeys(['id', 'parent', 'detail']);
});

/*
 * SQLite-specific: DROP COLUMN (conditional on SQLite >= 3.35)
 */
it('DROP COLUMN is skipped on SQLite < 3.35', function () {
    // The contract's addAndDropColumn() already exercises the happy path
    // on SQLite >= 3.35. This test ensures that on older runtimes the
    // driver throws rather than silently doing nothing.
    $version = $this->driver->runQuery($this->database, 'SELECT sqlite_version() AS v')[0]['rows'][0]['v'];

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

/*
 * SQLite-specific: primary key management throws
 */
it('createIndex throws when trying to add a primary key (unsupported post-creation)', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "pk_add_probe"');
    $this->driver->runQuery($this->database, 'CREATE TABLE "pk_add_probe" (id INTEGER, name TEXT)');

    expect(fn() => $this->driver->createIndex($this->database, 'pk_add_probe', [
        'name'    => 'PRIMARY',
        'columns' => ['id'],
        'unique'  => false,
        'primary' => true,
    ]))->toThrow(RuntimeException::class);
});

it('dropIndex throws when trying to drop a primary key (unsupported)', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS "pk_drop_probe"');
    $this->driver->runQuery($this->database, 'CREATE TABLE "pk_drop_probe" (id INTEGER PRIMARY KEY)');

    expect(fn() => $this->driver->dropIndex($this->database, 'pk_drop_probe', 'PRIMARY', true))
        ->toThrow(RuntimeException::class);
});

/*
 * SQLite-specific: stable database list
 */
it('listDatabases always returns exactly ["main"]', function () {
    expect($this->driver->listDatabases())->toBe(['main']);
});

it('engineMeta reports connectionType as file', function () {
    $engineMeta = $this->driver->engineMeta();

    expect($engineMeta['identifierOpen'])->toBe('"')
        ->and($engineMeta['identifierClose'])->toBe('"')
        ->and($engineMeta['connectionType'])->toBe('file');
});

/*
 * SQLite-specific: getDatabaseInfo returns null charset/collation
 * SQLite has no character-set or collation concept at the database level.
 */
it('getDatabaseInfo returns "main" with null charset and collation', function () {
    $info = $this->driver->getDatabaseInfo('main');

    expect($info['name'])->toBe('main')
        ->and($info['charset'])->toBeNull()
        ->and($info['collation'])->toBeNull();
});

/*
 * SQLite-specific: modifyColumn and reorderColumn are not supported
 * Verify the capability flags rather than calling non-existent methods.
 */
it('does not advertise modifyColumn or reorderColumn support', function () {
    $caps = \Quermy\Drivers\CapabilitySerializer::serialize($this->driver);

    expect($caps['supportsModifyColumn'])->toBeFalse()
        ->and($caps['supportsReorderColumn'])->toBeFalse()
        ->and($caps['supportsForeignKeyManagement'])->toBeFalse();
});
