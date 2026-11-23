<?php

use Quermy\Http\RequestGuard;

test('local clients may use local hostnames', function (string $host) {
    expect(RequestGuard::hostAllowed($host, '127.0.0.1', null))->toBeTrue();
})->with([
    'localhost',
    'localhost:8000',
    'LOCALHOST',
    '127.0.0.1',
    '127.0.0.1:8377',
    '192.168.1.20',
    '[::1]',
    '[::1]:8000',
    'quermy.test',
    'app.localhost:5173',
]);

test('local clients may not use outside domain names (DNS rebinding)', function (string $host) {
    expect(RequestGuard::hostAllowed($host, '127.0.0.1', null))->toBeFalse();
    expect(RequestGuard::hostAllowed($host, '::1', null))->toBeFalse();
})->with([
    'attacker.example',
    'attacker.example:80',
    'localhost.attacker.example',
    '127.0.0.1.attacker.example',
    'test',
    '',
]);

test('remote clients are accepted for any hostname when nothing is configured', function () {
    expect(RequestGuard::hostAllowed('db.example.com', '203.0.113.7', null))->toBeTrue();
});

test('a configured host list is authoritative for every client', function () {
    $allowed = ['db.example.com', '*.internal.example'];

    expect(RequestGuard::hostAllowed('db.example.com', '203.0.113.7', $allowed))->toBeTrue();
    expect(RequestGuard::hostAllowed('DB.Example.com:443', '127.0.0.1', $allowed))->toBeTrue();
    expect(RequestGuard::hostAllowed('a.internal.example', '203.0.113.7', $allowed))->toBeTrue();

    expect(RequestGuard::hostAllowed('attacker.example', '203.0.113.7', $allowed))->toBeFalse();
    expect(RequestGuard::hostAllowed('internal.example', '203.0.113.7', $allowed))->toBeFalse();
    expect(RequestGuard::hostAllowed('localhost', '127.0.0.1', $allowed))->toBeFalse();
});

test('requests without an Origin header pass the origin check', function () {
    expect(RequestGuard::originAllowed(null, 'localhost:8000', []))->toBeTrue();
    expect(RequestGuard::originAllowed('', 'localhost:8000', []))->toBeTrue();
});

test('same-origin requests pass the origin check', function (string $origin, string $host) {
    expect(RequestGuard::originAllowed($origin, $host, []))->toBeTrue();
})->with([
    ['http://localhost:8000', 'localhost:8000'],
    ['http://localhost', 'localhost'],
    ['https://db.example.com', 'db.example.com'],
    ['http://LocalHost:5173', 'localhost:5173'],
]);

test('cross-origin requests are refused', function (string $origin, string $host) {
    expect(RequestGuard::originAllowed($origin, $host, []))->toBeFalse();
})->with([
    ['https://attacker.example', 'localhost'],
    ['http://localhost:3000', 'localhost'],          // another app on this machine
    ['http://localhost:5173', 'localhost:8000'],
    ['http://127.0.0.1', 'localhost'],
    ['null', 'localhost'],
    ['http://localhost/path', 'localhost'],
]);

test('listed dev origins are allowed cross-origin, exactly as written', function () {
    $dev = ['http://localhost:5173'];

    expect(RequestGuard::originAllowed('http://localhost:5173', 'localhost:8000', $dev))->toBeTrue();
    expect(RequestGuard::isDevOrigin('http://localhost:5173', $dev))->toBeTrue();

    expect(RequestGuard::originAllowed('http://localhost:5174', 'localhost:8000', $dev))->toBeFalse();
    expect(RequestGuard::isDevOrigin(null, $dev))->toBeFalse();
    expect(RequestGuard::isDevOrigin('', ['']))->toBeFalse();
});
