<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Http\Json;

abstract class BaseController
{
    protected function requireFields(array $body, array $fields): void
    {
        foreach ($fields as $f) {
            if (!array_key_exists($f, $body) || $body[$f] === '') {
                Json::error("Missing field: $f", 422);
            }
        }
    }
}
