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
 * applies), plus MariaDB-specific checks (UUID type, INET4/INET6,
 * AUTO_INCREMENT, incoming foreign keys).
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
        ->and($meta['identifierOpen'])->toBe('`')
        ->and($meta['connectionType'])->toBe('tcp');
});

it('capabilities include MariaDB-specific types', function () {
    $caps = $this->driver->getCapabilities();

    expect($caps['columnTypes'])->toContain('UUID')
        ->and($caps['columnTypes'])->toContain('INET4')
        ->and($caps['columnTypes'])->toContain('INET6');
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
 * MariaDB-specific quirks
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
