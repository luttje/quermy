<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Drivers\Capabilities\SupportsAddColumn;
use Quermy\Drivers\Capabilities\SupportsDropColumn;
use Quermy\Drivers\Capabilities\SupportsModifyColumn;
use Quermy\Drivers\Capabilities\SupportsReorderColumn;
use Quermy\Http\ConnectionSessionInterface;
use Quermy\Http\Json;
use Quermy\Http\Route;
use RuntimeException;

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
            if (!$driver instanceof SupportsAddColumn) {
                throw new RuntimeException('This engine does not support adding columns.');
            }
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
            if (!$driver instanceof SupportsModifyColumn) {
                throw new RuntimeException('This engine does not support modifying columns.');
            }
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
            if (!$driver instanceof SupportsDropColumn) {
                throw new RuntimeException('This engine does not support dropping columns.');
            }
            $driver->dropColumn($db, $table, $column);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('PATCH', '/api/databases/{db}/tables/{table}/columns/{column}/position')]
    public function reorder(string $db, string $table, string $column): void
    {
        $body = Json::readBody();
        // 'after' is the column name to position after, or null to move first.
        $after = array_key_exists('after', $body) ? ($body['after'] !== null ? (string)$body['after'] : null) : null;

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsReorderColumn) {
                throw new RuntimeException('This engine does not support reordering columns.');
            }
            $driver->reorderColumn($db, $table, $column, $after);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }
}
