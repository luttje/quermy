<?php

use Quermy\Drivers\ConnectionParams;

test('accepts ordinary hosts', function (string $host) {
    expect(ConnectionParams::host($host))->toBe($host);
})->with([
    '127.0.0.1',
    'localhost',
    'db.internal.example.com',
    'my-db_1',
    '::1',
    '[2001:db8::1]',
    'SQLHOST\\SQLEXPRESS',
]);

test('rejects hosts that could extend the DSN', function (mixed $host) {
    ConnectionParams::host($host);
})->with([
    '127.0.0.1;port=1',
    'a;unix_socket=/tmp/mysql.sock',
    'host name',
    'a=b',
    'a,1433',
    "a\n",
    '',
    [['array']],
    null,
])->throws(InvalidArgumentException::class);

test('accepts ordinary database names', function (string $name) {
    expect(ConnectionParams::database($name))->toBe($name);
})->with(['app', 'my_db', 'my-db', 'db.v2', 'tenant$1']);

test('treats an empty database as none', function () {
    expect(ConnectionParams::database(null))->toBeNull();
    expect(ConnectionParams::database(''))->toBeNull();
});

test('rejects database names that could extend the DSN', function (mixed $name) {
    ConnectionParams::database($name);
})->with([
    'x;host=10.0.0.5',
    'x;port=47631',
    'x host=10.0.0.5',
    'x=y',
    "x'",
    "x\n",
    [['array']],
])->throws(InvalidArgumentException::class);

test('normalises valid ports', function () {
    expect(ConnectionParams::port(3306))->toBe(3306);
    expect(ConnectionParams::port('5432'))->toBe(5432);
});

test('rejects invalid ports', function (mixed $port) {
    ConnectionParams::port($port);
})->with([0, 65536, -1, '3306;host=x', '', 1.5, null])->throws(InvalidArgumentException::class);

test('rejects unusable usernames', function (mixed $username) {
    ConnectionParams::username($username);
})->with([[['array']], null, "root\0", str_repeat('a', 256)])->throws(InvalidArgumentException::class);
