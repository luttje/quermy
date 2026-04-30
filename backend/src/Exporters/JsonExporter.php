<?php declare(strict_types=1);

namespace Quermy\Exporters;

final class JsonExporter implements ExporterInterface
{
    public static function formatId(): string
    {
        return 'json';
    }

    public function contentType(): string
    {
        return 'application/json';
    }

    public function extension(): string
    {
        return 'json';
    }

    public function export(array $export): string
    {
        $out = [
            'exporter' => 'quermy',
            'generated' => date('c'),
            'databases' => []
        ];

        foreach ($export as $dbEntry) {
            $tables = [];
            foreach ($dbEntry['tables'] as $tbl) {
                $entry = [];
                if (!empty($tbl['columns'])) {
                    $entry['columns'] = $tbl['columns'];
                }
                if (!empty($tbl['rows'])) {
                    $entry['rows'] = $tbl['rows'];
                }
                $tables[$tbl['table']] = $entry;
            }
            $out['databases'][$dbEntry['database']] = ['tables' => $tables];
        }

        return (string) json_encode(
            $out,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }
}
