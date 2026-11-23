<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Drivers\CapabilitySerializer;
use Quermy\Exporters\ExporterFactory;
use Quermy\Http\ConnectionSessionInterface;
use Quermy\Http\Json;
use Quermy\Http\Route;
use RuntimeException;

final class ExportController extends BaseController
{
    /** How much of an export is kept in memory before spilling to a temp file. */
    private const BUFFER_MEMORY_BYTES = 8 * 1024 * 1024;

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

        // An export can run for a while. Release the session lock so the
        // user's other requests aren't stuck waiting behind it; the
        // credentials already read into $_SESSION stay available.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $driver = $this->session->open();
        try {
            $caps = CapabilitySerializer::serialize($driver);
            $export = $this->collect($driver, $caps, $selection, $includeStructure, $includeData);

            $exporter = ExporterFactory::make($format, [
                'caps'             => $caps,
                'includeStructure' => $includeStructure,
                'includeData'      => $includeData,
            ]);

            // Write the export to a temporary stream first: it stays in
            // memory up to a few megabytes and spills to a temp file on disk
            // beyond that, so the size of the tables no longer decides how
            // much memory this request needs. Only once the whole export has
            // succeeded is anything sent, which keeps failures reportable as
            // a normal JSON error instead of a silently truncated download.
            $buffer = fopen('php://temp/maxmemory:' . self::BUFFER_MEMORY_BYTES, 'w+b');
            if ($buffer === false) {
                throw new RuntimeException('Could not create a temporary buffer for the export.');
            }
            $exporter->export($export, $buffer);
        } finally {
            $driver->disconnect();
        }

        $size     = ftell($buffer);
        $filename = 'quermy_export_' . date('Ymd_His') . '.' . $exporter->extension();
        rewind($buffer);

        // Drop PHP's output buffers so the download is passed straight
        // through rather than being collected in memory again.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(200);
        header('Content-Type: ' . $exporter->contentType() . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        if ($size !== false) {
            header('Content-Length: ' . $size);
        }
        fpassthru($buffer);
        fclose($buffer);
        exit;
    }

    /**
     * Describe what to export, in the normalised structure consumed by every
     * ExporterInterface implementation.
     *
     * Table metadata (columns, DDL, row count) is fetched here, up front, so
     * a missing table or a permission problem surfaces before any output is
     * produced. Row data is not fetched here: each table's `rows` is a
     * generator that reads the table page by page while the exporter writes.
     *
     * @param mixed                              $driver
     * @param array<string,mixed>                $caps              result of CapabilitySerializer::serialize($driver)
     * @param array<string,array<int,string>>    $selection         database → list of tables
     * @return list<array{database:string,tables:list<array{table:string,ddl:string|null,columns:list<array<string,mixed>>,rows:iterable<array<string,mixed>>}>}>
     */
    /**
     * Yield a table's rows one at a time, fetching 1 000 at a time from the
     * driver (its built-in page cap). Only one page is in memory at once.
     *
     * @param mixed $driver
     * @return \Generator<int,array<string,mixed>>
     */
    private function rows($driver, string $db, string $table, int $total): \Generator
    {
        $pageSize = 1000;
        for ($offset = 0; $offset < $total; $offset += $pageSize) {
            $page = $driver->browseTable($db, $table, $pageSize, $offset)['rows'];
            if ($page === []) {
                return; // table shrank since the row count was taken
            }
            foreach ($page as $row) {
                yield $row;
            }
        }
    }

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
                    $tableEntry['rows'] = $this->rows($driver, (string) $db, $table, (int) $meta['total']);
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
