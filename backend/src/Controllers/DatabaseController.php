<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Http\ConnectionSessionInterface;
use Quermy\Http\Json;
use Quermy\Http\Route;

final class DatabaseController extends BaseController
{
    public function __construct(
        private ConnectionSessionInterface $session,
    ) {}

    #[Route('GET', '/api/databases')]
    public function listDatabases(): void
    {
        $driver = $this->session->open();
        try {
            Json::send(['databases' => $driver->listDatabases()]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/tables')]
    public function listTables(string $db): void
    {
        $driver = $this->session->open();
        try {
            Json::send(['tables' => $driver->listTables($db)]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/tables/{table}')]
    public function browseTable(string $db, string $table): void
    {
        $limit  = (int)($_GET['limit']  ?? 100);
        $offset = (int)($_GET['offset'] ?? 0);

        $driver = $this->session->open();
        try {
            Json::send($driver->browseTable($db, $table, $limit, $offset));
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('POST', '/api/query')]
    public function runQuery(): void
    {
        $body = Json::readBody();
        $sql  = trim((string)($body['sql']      ?? ''));
        $db   = (string)       ($body['database'] ?? '');
        if ($sql === '') Json::error('SQL is empty', 400);

        $driver = $this->session->open();
        try {
            Json::send($driver->runQuery($db, $sql));
        } finally {
            $driver->disconnect();
        }
    }
}
