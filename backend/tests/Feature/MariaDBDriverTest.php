<?php declare(strict_types=1);

use Quermy\Drivers\DriverFactory;
use Quermy\Drivers\MariaDBDriver;
use Tests\Support\BootedEngine;
use Tests\Support\ContainerSupport;
use Tests\Support\DriverContract;
use Tests\Support\EngineDialect;
use Testcontainers\Modules\MariaDBContainer;
use Testcontainers\Wait\WaitForLog;

/*
 * MariaDB driver feature tests.
 *
 * Boots a real MariaDB 11 container once per file, runs the shared driver
 * contract (MariaDB is wire-compatible with MySQL so the same dialect
 * applies), plus MariaDB-specific checks (UUID/INET types, AUTO_INCREMENT,
 * incoming foreign keys, index management, FK management, column reordering).
 */

beforeAll(function () {
    ContainerSupport::skipIfNotInstalled(MariaDBContainer::class, 'testcontainers/testcontainers');
    ContainerSupport::skipIfDockerUnavailable();

    $container = (new MariaDBContainer('11', 'rootsecret'))
        ->withMariaDBDatabase('quermy_test')
        ->withMariaDBUser('quermy', 'secret')
        ->withWait(new WaitForLog('port: 3306', false, 60000))
        ->start();

    $driver = DriverFactory::make('mariadb');
    $driver->connect([
        'host'     => $container->getHost(),
        'port'     => (int)$container->getFirstMappedPort(),
        'username' => 'root',
        'password' => 'rootsecret',
        'database' => 'quermy_test',
    ]);

    BootedEngine::set('mariadb', new BootedEngine($container, $driver, 'quermy_test'));
});

afterAll(function () {
    BootedEngine::tearDown('mariadb');
});

beforeEach(function () {
    $booted = BootedEngine::get('mariadb');

    if ($booted === null) {
        test()->markTestSkipped('MariaDB container is not available.');
    }

    /** @disregard */
    $this->driver = $booted->driver;

    /** @disregard */
    $this->database = $booted->database;

    /** @disregard */
    $this->contract = new DriverContract($this->driver, $this->database, EngineDialect::mariadb());
});

/*
 * Static metadata
 */
it('reports its engine id and metadata', function () {
    expect(MariaDBDriver::engineId())->toBe('mariadb');

    $meta = MariaDBDriver::engineMeta();
    expect($meta['id'])->toBe('mariadb')
        ->and($meta['label'])->toBe('MariaDB')
        ->and($meta['defaultPort'])->toBe(3306)
        ->and($meta['defaultUsername'])->toBe('root')
        ->and($meta['identifierOpen'])->toBe('`')
        ->and($meta['identifierClose'])->toBe('`')
        ->and($meta['connectionType'])->toBe('tcp');
});

it('capabilities include MariaDB-specific types', function () {
    $caps = $this->driver->getCapabilities();

    expect($caps['columnTypes'])->toContain('UUID')
        ->and($caps['columnTypes'])->toContain('INET4')
        ->and($caps['columnTypes'])->toContain('INET6');
});

it('capabilities report MariaDB-appropriate flags', function () {
    $caps = $this->driver->getCapabilities();

    expect($caps['supportsAutoIncrement'])->toBeTrue()
        ->and($caps['supportsColumnAfter'])->toBeTrue()
        ->and($caps['supportsModifyColumn'])->toBeTrue()
        ->and($caps['supportsDropColumn'])->toBeTrue()
        ->and($caps['supportsReorderColumn'])->toBeTrue()
        ->and($caps['supportsGetCreateTable'])->toBeTrue()
        ->and($caps['supportsExplain'])->toBeTrue()
        ->and($caps['supportsForeignKeys'])->toBeTrue()
        ->and($caps['supportsIndexManagement'])->toBeTrue()
        ->and($caps['supportsPrimaryKeyManagement'])->toBeTrue()
        ->and($caps['supportsForeignKeyManagement'])->toBeTrue()
        ->and($caps['identifierOpen'])->toBe('`')
        ->and($caps['identifierClose'])->toBe('`');
});

/*
 * Shared contract — capabilities
 */
it('exposes capabilities of the expected shape', fn() => $this->contract->capabilitiesShape());

/*
 * Shared contract — databases & tables
 */
it('lists databases including the target', fn() => $this->contract->listDatabasesIncludesTarget());
it('lists tables created in the database', fn() => $this->contract->listTablesShowsCreatedTables());
it('listTables includes rows and size metadata', fn() => $this->contract->listTablesIncludesRowsAndSize());

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
 * Shared contract — column reordering
 */
it('reorderColumn moves a column to the first position', fn() => $this->contract->reorderColumnMovesToFirst());
it('reorderColumn moves a column after a named target', fn() => $this->contract->reorderColumnMovesAfterTarget());

/*
 * MariaDB-specific: incoming foreign keys
 */
it('reports incoming foreign keys on the parent table', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS `fk_child_in`');
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS `fk_parent_in`');
    $this->driver->runQuery($this->database, 'CREATE TABLE `fk_parent_in` (id INT PRIMARY KEY) ENGINE=InnoDB');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE `fk_child_in` (
            id        INT PRIMARY KEY,
            parent_id INT,
            CONSTRAINT fk_in FOREIGN KEY (parent_id) REFERENCES `fk_parent_in` (id)
        ) ENGINE=InnoDB'
    );

    $parentFks = $this->driver->getForeignKeys($this->database, 'fk_parent_in');
    expect($parentFks['incoming'])->not->toBeEmpty()
        ->and($parentFks['incoming'][0]['referencingTable'])->toBe('fk_child_in');
});

it('incoming FK entries have all required keys', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS `fk_shape_child_in`');
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS `fk_shape_parent_in`');
    $this->driver->runQuery($this->database, 'CREATE TABLE `fk_shape_parent_in` (id INT PRIMARY KEY) ENGINE=InnoDB');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE `fk_shape_child_in` (
            id        INT PRIMARY KEY,
            parent_id INT,
            CONSTRAINT fk_shape_in FOREIGN KEY (parent_id) REFERENCES `fk_shape_parent_in` (id)
        ) ENGINE=InnoDB'
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
 * MariaDB-specific: AUTO_INCREMENT
 */
it('handles AUTO_INCREMENT and reports the insert id', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS `autoinc_probe`');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE `autoinc_probe` (
            id   INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50)
         ) ENGINE=InnoDB'
    );

    $first  = $this->driver->insertRow($this->database, 'autoinc_probe', ['name' => 'a']);
    $second = $this->driver->insertRow($this->database, 'autoinc_probe', ['name' => 'b']);

    expect($first['insertId'])->toBe(1)
        ->and($second['insertId'])->toBe(2);
});

it('describeTable marks AUTO_INCREMENT column as autoIncrement=true', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS `autoinc_desc_probe`');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE `autoinc_desc_probe` (
            id   INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50)
         ) ENGINE=InnoDB'
    );

    $info   = $this->driver->describeTable($this->database, 'autoinc_desc_probe');
    $byName = array_column($info['columns'], null, 'name');

    expect($byName['id']['autoIncrement'])->toBeTrue()
        ->and($byName['name']['autoIncrement'])->toBeFalse();
});

/*
 * MariaDB-specific: ADD COLUMN … AFTER
 */
it('supports adding a column AFTER another column', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS `after_probe`');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE `after_probe` (id INT PRIMARY KEY, name VARCHAR(50)) ENGINE=InnoDB'
    );

    $this->driver->addColumn($this->database, 'after_probe', [
        'name'     => 'inserted',
        'type'     => 'VARCHAR(20)',
        'nullable' => true,
        'default'  => null,
        'after'    => 'id',
    ]);

    $cols = array_column(
        $this->driver->describeTable($this->database, 'after_probe')['columns'],
        'name'
    );
    expect(array_search('inserted', $cols))->toBe(1);
});

/*
 * MariaDB-specific: CHANGE COLUMN (rename + retype)
 */
it('modifies a column in place (rename + retype)', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS `modify_probe`');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE `modify_probe` (id INT PRIMARY KEY, original VARCHAR(20)) ENGINE=InnoDB'
    );

    $this->driver->modifyColumn($this->database, 'modify_probe', 'original', [
        'name'     => 'renamed',
        'type'     => 'VARCHAR(100)',
        'nullable' => false,
        'default'  => null,
    ]);

    $cols   = $this->driver->describeTable($this->database, 'modify_probe')['columns'];
    $byName = [];
    foreach ($cols as $c) { $byName[$c['name']] = $c; }

    expect($byName)->toHaveKey('renamed')
        ->and($byName)->not->toHaveKey('original')
        ->and($byName['renamed']['nullable'])->toBeFalse();
});

/*
 * MariaDB-specific: PRIMARY KEY management
 */
it('creates and drops a primary key constraint', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS `pk_mgmt_probe`');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE `pk_mgmt_probe` (id INT NOT NULL, name VARCHAR(50)) ENGINE=InnoDB'
    );

    $this->driver->createIndex($this->database, 'pk_mgmt_probe', [
        'name'    => 'PRIMARY',
        'columns' => ['id'],
        'unique'  => false,
        'primary' => true,
    ]);

    $info = $this->driver->describeTable($this->database, 'pk_mgmt_probe');
    expect($info['primaryKey'])->toBe(['id']);

    $this->driver->dropIndex($this->database, 'pk_mgmt_probe', 'PRIMARY', true);

    $info = $this->driver->describeTable($this->database, 'pk_mgmt_probe');
    expect($info['primaryKey'])->toBeEmpty();
});

/*
 * MariaDB-specific: JSON round-trip
 */
it('roundtrips JSON columns', function () {
    $this->driver->runQuery($this->database, 'DROP TABLE IF EXISTS `json_probe`');
    $this->driver->runQuery(
        $this->database,
        'CREATE TABLE `json_probe` (id INT PRIMARY KEY, payload JSON) ENGINE=InnoDB'
    );

    $payload = json_encode(['hello' => 'world', 'n' => 42]);
    $this->driver->insertRow($this->database, 'json_probe', ['id' => 1, 'payload' => $payload]);

    $row = $this->driver->browseTable($this->database, 'json_probe', 10, 0)['rows'][0];
    expect(json_decode($row['payload'], true))->toEqual(['hello' => 'world', 'n' => 42]);
});
