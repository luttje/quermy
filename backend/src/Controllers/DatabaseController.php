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

    #[Route('GET', '/api/databases/{db}/collations')]
    public function listDatabaseCollations(string $db): void
    {
        $driver = $this->session->open();
        try {
            Json::send(['collations' => $driver->listDatabaseCollations($db)]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('GET', '/api/databases/{db}/info')]
    public function getDatabaseInfo(string $db): void
    {
        $driver = $this->session->open();
        try {
            Json::send($driver->getDatabaseInfo($db));
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('POST', '/api/databases/{db}/rename')]
    public function renameDatabase(string $db): void
    {
        $body    = Json::readBody();
        $newName = trim((string)($body['newName'] ?? ''));
        if ($newName === '') Json::error('newName is required', 400);

        $driver = $this->session->open();
        try {
            $driver->renameDatabase($db, $newName);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('PATCH', '/api/databases/{db}/collation')]
    public function alterDatabaseCollation(string $db): void
    {
        $body      = Json::readBody();
        $collation = trim((string)($body['collation'] ?? ''));
        if ($collation === '') Json::error('collation is required', 400);

        $driver = $this->session->open();
        try {
            $driver->alterDatabaseCollation($db, $collation);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('DELETE', '/api/databases/{db}')]
    public function dropDatabase(string $db): void
    {
        $driver = $this->session->open();
        try {
            $driver->dropDatabase($db);
            Json::send(['ok' => true]);
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
