<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Http\ConnectionSessionInterface;
use Quermy\Http\Json;
use Quermy\Http\Route;

final class ColumnController extends BaseController
{
    public function __construct(
        private ConnectionSessionInterface $session,
    ) {}

    #[Route('POST', '/api/databases/{db}/tables/{table}/columns')]
    public function add(string $db, string $table): void
    {
        $body = Json::readBody();
        $this->requireFields($body, ['name', 'type']);

        $driver = $this->session->open();
        try {
            $driver->addColumn($db, $table, $body);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('PUT', '/api/databases/{db}/tables/{table}/columns/{column}')]
    public function modify(string $db, string $table, string $column): void
    {
        $body = Json::readBody();
        $this->requireFields($body, ['name', 'type']);

        $driver = $this->session->open();
        try {
            $driver->modifyColumn($db, $table, $column, $body);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('DELETE', '/api/databases/{db}/tables/{table}/columns/{column}')]
    public function drop(string $db, string $table, string $column): void
    {
        $driver = $this->session->open();
        try {
            $driver->dropColumn($db, $table, $column);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }
}
