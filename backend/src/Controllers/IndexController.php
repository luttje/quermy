<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Drivers\Capabilities\SupportsIndexManagement;
use Quermy\Http\ConnectionSessionInterface;
use Quermy\Http\Json;
use Quermy\Http\Route;
use RuntimeException;

final class IndexController extends BaseController
{
    public function __construct(
        private ConnectionSessionInterface $session,
    ) {}

    #[Route('GET', '/api/databases/{db}/tables/{table}/indexes')]
    public function list(string $db, string $table): void
    {
        $driver = $this->session->open();
        try {
            $result  = $driver->describeTable($db, $table);
            $pkCols  = $result['primaryKey'];
            sort($pkCols);

            $indexes = array_map(static function (array $idx) use ($pkCols): array {
                // MySQL names the PK index "PRIMARY"; for other engines compare columns.
                $idxCols = $idx['columns'];
                sort($idxCols);
                $isPrimary = $idx['name'] === 'PRIMARY'
                    || (!empty($pkCols) && $idx['unique'] && $idxCols === $pkCols);

                return [
                    'name'    => $idx['name'],
                    'columns' => $idx['columns'],
                    'unique'  => $idx['unique'],
                    'primary' => $isPrimary,
                ];
            }, $result['indexes']);

            Json::send(['indexes' => $indexes]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('POST', '/api/databases/{db}/tables/{table}/indexes')]
    public function create(string $db, string $table): void
    {
        $body = Json::readBody();

        if (empty($body['primary'])) {
            $this->requireFields($body, ['name', 'columns']);
        } else {
            $this->requireFields($body, ['columns']);
        }

        if (!is_array($body['columns']) || $body['columns'] === []) {
            Json::error('columns must be a non-empty array', 400);
            return;
        }

        $definition = [
            'name'    => $body['name'] ?? '',
            'columns' => array_map('strval', $body['columns']),
            'unique'  => !empty($body['unique']),
            'primary' => !empty($body['primary']),
        ];

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsIndexManagement) {
                throw new RuntimeException('This engine does not support index management.');
            }
            $driver->createIndex($db, $table, $definition);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('DELETE', '/api/databases/{db}/tables/{table}/indexes/{index}')]
    public function drop(string $db, string $table, string $index): void
    {
        $body      = Json::readBody();
        $isPrimary = !empty($body['isPrimary']);

        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsIndexManagement) {
                throw new RuntimeException('This engine does not support index management.');
            }
            $driver->dropIndex($db, $table, $index, $isPrimary);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }
}
