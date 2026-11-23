<?php

use Quermy\Exporters\ExporterFactory;

/**
 * Build an export structure whose rows come from a generator, the way
 * ExportController supplies them. A generator can only be walked once, so
 * these tests fail if an exporter tries to count, index, or re-read rows.
 */
function lazyExport(array $columns, array $rows): array
{
    return [[
        'database' => 'shop',
        'tables'   => [[
            'table'   => 'items',
            'ddl'     => 'CREATE TABLE items (id int, name text)',
            'columns' => $columns,
            'rows'    => (function () use ($rows) { yield from $rows; })(),
        ]],
    ]];
}

function runExport(string $format, array $export, bool $structure = true, bool $data = true): string
{
    $exporter = ExporterFactory::make($format, [
        'caps'             => ['identifierOpen' => '`', 'identifierClose' => '`'],
        'includeStructure' => $structure,
        'includeData'      => $data,
    ]);

    $out = fopen('php://temp', 'w+b');
    $exporter->export($export, $out);
    rewind($out);

    return (string) stream_get_contents($out);
}

$columns = [
    ['name' => 'id', 'type' => 'int'],
    ['name' => 'name', 'type' => 'text'],
];
$rows = [
    ['id' => 1, 'name' => "O'Brien"],
    ['id' => 2, 'name' => null],
    ['id' => 3, 'name' => 'a "quoted", value'],
];

test('sql export writes structure and one insert per row', function () use ($columns, $rows) {
    $sql = runExport('sql', lazyExport($columns, $rows));

    expect($sql)->toContain('DROP TABLE IF EXISTS `shop`.`items`;')
        ->and($sql)->toContain('CREATE TABLE items (id int, name text);')
        ->and($sql)->toContain("INSERT INTO `shop`.`items` (`id`, `name`) VALUES ('1', 'O\\'Brien');")
        ->and($sql)->toContain("INSERT INTO `shop`.`items` (`id`, `name`) VALUES ('2', NULL);")
        ->and(substr_count($sql, 'INSERT INTO'))->toBe(3);
});

test('sql export takes column names from the first row when there is no column metadata', function () use ($rows) {
    $sql = runExport('sql', lazyExport([], $rows), structure: false);

    expect($sql)->toContain('INSERT INTO `shop`.`items` (`id`, `name`) VALUES')
        ->and($sql)->not->toContain('CREATE TABLE');
});

test('csv export writes a header and every row', function () use ($columns, $rows) {
    $lines = array_values(array_filter(
        explode("\n", runExport('csv', lazyExport($columns, $rows))),
        fn($l) => $l !== '' && !str_starts_with($l, '#'),
    ));

    expect($lines)->toHaveCount(4)
        ->and($lines[0])->toBe('id,name')
        ->and(str_getcsv($lines[3]))->toBe(['3', 'a "quoted", value']);
});

test('csv export derives the header from the first row when there is no column metadata', function () use ($rows) {
    $csv = runExport('csv', lazyExport([], $rows), structure: false);

    expect($csv)->toContain("# shop.items\nid,name\n1,O'Brien\n");
});

test('json export is valid JSON containing columns and rows', function () use ($columns, $rows) {
    $data = json_decode(runExport('json', lazyExport($columns, $rows)), true, flags: JSON_THROW_ON_ERROR);

    expect($data['exporter'])->toBe('quermy')
        ->and($data['databases']['shop']['tables']['items']['columns'])->toBe($columns)
        ->and($data['databases']['shop']['tables']['items']['rows'])->toBe($rows);
});

test('json export stays valid when a table has no rows or no columns', function () use ($columns, $rows) {
    $noRows = json_decode(runExport('json', lazyExport($columns, [])), true, flags: JSON_THROW_ON_ERROR);
    expect($noRows['databases']['shop']['tables']['items'])->toBe(['columns' => $columns]);

    $noCols = json_decode(runExport('json', lazyExport([], $rows)), true, flags: JSON_THROW_ON_ERROR);
    expect($noCols['databases']['shop']['tables']['items'])->toBe(['rows' => $rows]);

    $neither = json_decode(runExport('json', lazyExport([], [])), true, flags: JSON_THROW_ON_ERROR);
    expect($neither['databases']['shop']['tables']['items'])->toBe([]);

    $empty = json_decode(runExport('json', []), true, flags: JSON_THROW_ON_ERROR);
    expect($empty['databases'])->toBe([]);
});

test('xml export is well-formed and contains every row', function () use ($columns, $rows) {
    $doc = new DOMDocument();
    expect($doc->loadXML(runExport('xml', lazyExport($columns, $rows))))->toBeTrue();

    $xp = new DOMXPath($doc);
    expect($xp->query('/export/database[@name="shop"]/table[@name="items"]/structure/column')->length)->toBe(2)
        ->and($xp->query('//data/row')->length)->toBe(3)
        ->and($xp->query('//data/row[1]/name')->item(0)->textContent)->toBe("O'Brien")
        ->and($xp->query('//data/row[2]/name')->item(0)->getAttribute('null'))->toBe('true');
});

test('xml export omits the data element for a table without rows', function () use ($columns) {
    $doc = new DOMDocument();
    $doc->loadXML(runExport('xml', lazyExport($columns, [])));

    expect((new DOMXPath($doc))->query('//data')->length)->toBe(0);
});

test('exporting many rows does not hold them all in memory', function (string $format) {
    $rowCount = 200_000;
    $export = [[
        'database' => 'big',
        'tables'   => [[
            'table'   => 't',
            'ddl'     => null,
            'columns' => [['name' => 'id'], ['name' => 'payload']],
            'rows'    => (function () use ($rowCount) {
                for ($i = 0; $i < $rowCount; $i++) {
                    yield ['id' => $i, 'payload' => str_repeat('x', 100)];
                }
            })(),
        ]],
    ]];

    $exporter = ExporterFactory::make($format, [
        'caps' => ['identifierOpen' => '`', 'identifierClose' => '`'],
    ]);

    // Write to a null sink so only the exporter's own memory use is measured.
    $sink = fopen(PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'wb');
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $exporter->export($export, $sink);
    $grew = memory_get_peak_usage() - $before;

    // The rows alone are over 20 MB of payload; the exporter should need a
    // small fraction of that.
    expect($grew)->toBeLessThan(4 * 1024 * 1024);
})->with(['sql', 'csv', 'json', 'xml']);
