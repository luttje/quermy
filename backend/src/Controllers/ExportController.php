<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Exporters\ExporterFactory;
use Quermy\Http\ConnectionSessionInterface;
use Quermy\Http\Json;
use Quermy\Http\Route;

final class ExportController extends BaseController
{
    public function __construct(
        private readonly ConnectionSessionInterface $session,
    ) {}

    #[Route('POST', '/api/export')]
    public function export(): void
    {
        $body             = Json::readBody();
        $format           = (string) ($body['format']           ?? 'sql');
        $includeStructure = (bool)   ($body['includeStructure'] ?? true);
        $includeData      = (bool)   ($body['includeData']      ?? true);
        $selection        = $body['databases'] ?? [];

        if (!in_array($format, ExporterFactory::supportedFormats(), true)) {
            Json::error('Invalid format', 400);
        }
        if (!is_array($selection) || empty($selection)) {
            Json::error('No databases/tables selected', 400);
        }
        if (!$includeStructure && !$includeData) {
            Json::error('Must include structure or data', 400);
        }

        $driver = $this->session->open();
        try {
            $caps = $driver->getCapabilities();
            $export = $this->collect($driver, $caps, $selection, $includeStructure, $includeData);

            $exporter = ExporterFactory::make($format, [
                'caps'             => $caps,
                'includeStructure' => $includeStructure,
                'includeData'      => $includeData,
            ]);

            $content  = $exporter->export($export);
            $filename = 'quermy_export_' . date('Ymd_His') . '.' . $exporter->extension();

            header('Content-Type: ' . $exporter->contentType() . '; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            http_response_code(200);
            echo $content;
        } finally {
            $driver->disconnect();
        }
        exit;
    }

    /**
     * Walk the driver and build the normalised export structure consumed by
     * every ExporterInterface implementation.
     *
     * @param mixed                              $driver
     * @param array<string,mixed>                $caps              result of $driver->getCapabilities()
     * @param array<string,array<int,string>>    $selection         database → list of tables
     * @return list<array{database:string,tables:list<array{table:string,ddl:string|null,columns:list<array<string,mixed>>,rows:list<array<string,mixed>>}>}>
     */
    private function collect(
        $driver,
        array $caps,
        array $selection,
        bool $includeStructure,
        bool $includeData,
    ): array {
        $supportsGetCreate  = (bool) ($caps['supportsGetCreateTable'] ?? false);

        $export = [];
        foreach ($selection as $db => $tables) {
            if (!is_array($tables) || empty($tables)) {
                continue;
            }
            $dbEntry = ['database' => (string) $db, 'tables' => []];

            foreach ($tables as $table) {
                $table      = (string) $table;
                $tableEntry = ['table' => $table, 'ddl' => null, 'columns' => [], 'rows' => []];

                // Always fetch column metadata; a limit of 1 is the minimum the
                // driver allows, giving us the column list with minimal overhead.
                $meta                  = $driver->browseTable($db, $table, 1, 0);
                $tableEntry['columns'] = $meta['columns'];

                if ($includeStructure && $supportsGetCreate) {
                    $tableEntry['ddl'] = $driver->getCreateTable($db, $table);
                }

                if ($includeData) {
                    // Paginate in 1 000-row pages to avoid the built-in cap.
                    $allRows  = [];
                    $pageSize = 1000;
                    $offset   = 0;
                    $total    = $meta['total'];
                    while ($offset < $total) {
                        $page    = $driver->browseTable($db, $table, $pageSize, $offset);
                        $allRows = array_merge($allRows, $page['rows']);
                        $offset += $pageSize;
                    }
                    $tableEntry['rows'] = $allRows;
                }

                if (!$includeStructure) {
                    $tableEntry['columns'] = [];
                }

                $dbEntry['tables'][] = $tableEntry;
            }
            $export[] = $dbEntry;
        }
        return $export;
    }
}
