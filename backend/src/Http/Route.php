<?php declare(strict_types=1);

namespace Quermy\Http;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class Route
{
    public function __construct(
        public string $method,   // 'GET', 'POST', etc.
        public string $path,     // '/api/databases/{db}/tables/{table}'
    ) {}
}
