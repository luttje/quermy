<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Http\ConnectionSessionInterface;
use Quermy\Http\Json;
use Quermy\Http\Route;

final class ForeignKeyController extends BaseController
{
    public function __construct(
        private ConnectionSessionInterface $session,
    ) {}

    #[Route('GET', '/api/databases/{db}/tables/{table}/foreign-keys')]
    public function list(string $db, string $table): void
    {
        $driver = $this->session->open();
        try {
            $raw     = $driver->getForeignKeys($db, $table);
            $outgoing = $this->groupForeignKeyRows($raw['outgoing'] ?? []);
            $incoming = $this->groupForeignKeyRows($raw['incoming'] ?? []);

            Json::send(['outgoing' => $outgoing, 'incoming' => $incoming]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('POST', '/api/databases/{db}/tables/{table}/foreign-keys')]
    public function create(string $db, string $table): void
    {
        $body = Json::readBody();
        $this->requireFields($body, ['name', 'columns', 'referencedTable', 'referencedColumns']);

        if (!is_array($body['columns']) || $body['columns'] === []) {
            Json::error('columns must be a non-empty array', 400);
            return;
        }
        if (!is_array($body['referencedColumns']) || $body['referencedColumns'] === []) {
            Json::error('referencedColumns must be a non-empty array', 400);
            return;
        }

        $definition = [
            'name'              => (string)$body['name'],
            'columns'           => array_map('strval', $body['columns']),
            'referencedTable'   => (string)$body['referencedTable'],
            'referencedColumns' => array_map('strval', $body['referencedColumns']),
            'onUpdate'          => (string)($body['onUpdate'] ?? 'RESTRICT'),
            'onDelete'          => (string)($body['onDelete'] ?? 'RESTRICT'),
        ];

        $driver = $this->session->open();
        try {
            $driver->createForeignKey($db, $table, $definition);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    #[Route('DELETE', '/api/databases/{db}/tables/{table}/foreign-keys/{constraint}')]
    public function drop(string $db, string $table, string $constraint): void
    {
        $driver = $this->session->open();
        try {
            $driver->dropForeignKey($db, $table, $constraint);
            Json::send(['ok' => true]);
        } finally {
            $driver->disconnect();
        }
    }

    /**
     * The raw getForeignKeys() result may contain one row per column pair.
     * Group them into one entry per constraint name, aggregating columns.
     *
     * @param  list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function groupForeignKeyRows(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $key = $row['constraintName'] ?? ($row['name'] ?? '');
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'constraintName'    => $key,
                    'referencedDatabase' => $row['referencedDatabase'] ?? '',
                    'columns'           => [],
                    'referencedTable'   => $row['referencedTable'] ?? $row['foreignTable'] ?? '',
                    'referencedColumns' => [],
                    'referencingDatabase' => $row['referencingDatabase'] ?? '',
                    'referencingTable'  => $row['referencingTable'] ?? '',
                    'referencingColumns' => [],
                    'onUpdate'          => $row['onUpdate'] ?? '',
                    'onDelete'          => $row['onDelete'] ?? '',
                ];
            }
            if (isset($row['column'])) {
                $grouped[$key]['columns'][] = $row['column'];
            }
            if (isset($row['referencedColumn'])) {
                $grouped[$key]['referencedColumns'][] = $row['referencedColumn'];
            }
            if (isset($row['referencingColumn'])) {
                $grouped[$key]['referencingColumns'][] = $row['referencingColumn'];
            }
            // Support pre-grouped rows too (already arrays)
            if (isset($row['columns']) && is_array($row['columns'])) {
                $grouped[$key]['columns'] = array_values(array_unique(
                    array_merge($grouped[$key]['columns'], $row['columns'])
                ));
            }
            if (isset($row['referencedColumns']) && is_array($row['referencedColumns'])) {
                $grouped[$key]['referencedColumns'] = array_values(array_unique(
                    array_merge($grouped[$key]['referencedColumns'], $row['referencedColumns'])
                ));
            }
            if (isset($row['referencingColumns']) && is_array($row['referencingColumns'])) {
                $grouped[$key]['referencingColumns'] = array_values(array_unique(
                    array_merge($grouped[$key]['referencingColumns'], $row['referencingColumns'])
                ));
            }
            if (empty($grouped[$key]['referencedTable']) && isset($row['referencedTable'])) {
                $grouped[$key]['referencedTable'] = (string)$row['referencedTable'];
            }
            if (empty($grouped[$key]['referencingTable']) && isset($row['referencingTable'])) {
                $grouped[$key]['referencingTable'] = (string)$row['referencingTable'];
            }
            if (empty($grouped[$key]['referencedDatabase']) && isset($row['referencedDatabase'])) {
                $grouped[$key]['referencedDatabase'] = (string)$row['referencedDatabase'];
            }
            if (empty($grouped[$key]['referencingDatabase']) && isset($row['referencingDatabase'])) {
                $grouped[$key]['referencingDatabase'] = (string)$row['referencingDatabase'];
            }
        }
        return array_values($grouped);
    }
}
