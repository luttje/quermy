<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Http\ConnectionSession;
use Quermy\Http\Json;
use Quermy\Http\Route;

final class RowController extends BaseController
{
    public function __construct(
        private ConnectionSession $session,
    ) {}

    #[Route('POST', '/api/databases/{db}/tables/{table}/rows')]
    public function insert(string $db, string $table): void
    {
        $body   = Json::readBody();
        $values = $body['values'] ?? [];
        if (empty($values)) Json::error('No values provided', 422);

        $driver = $this->session->open();
        try {
            Json::send($driver->insertRow($db, $table, $values));
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('PUT', '/api/databases/{db}/tables/{table}/rows')]
    public function update(string $db, string $table): void
    {
        $body   = Json::readBody();
        $where  = $body['where']  ?? [];
        $values = $body['values'] ?? [];
        if (empty($where))  Json::error('No WHERE conditions provided', 422);
        if (empty($values)) Json::error('No values to update',          422);

        $driver = $this->session->open();
        try {
            Json::send($driver->updateRow($db, $table, $where, $values));
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('DELETE', '/api/databases/{db}/tables/{table}/rows')]
    public function delete(string $db, string $table): void
    {
        $body  = Json::readBody();
        $where = $body['where'] ?? [];
        if (empty($where)) Json::error('No WHERE conditions provided', 422);

        $driver = $this->session->open();
        try {
            Json::send($driver->deleteRow($db, $table, $where));
        } finally {
            $driver->disconnect();
        }
    }
}
