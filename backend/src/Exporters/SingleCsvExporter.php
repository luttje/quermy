<?php declare(strict_types=1);

namespace Quermy\Exporters;

final class SingleCsvExporter implements ExporterInterface
{
    public static function formatId(): string
    {
        return 'csv';
    }

    public function contentType(): string
    {
        return 'text/csv';
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function export(array $export): string
    {
        $out = "# Quermy CSV export\n# Generated: " . date('c') . "\n\n";
        foreach ($export as $dbEntry) {
            foreach ($dbEntry['tables'] as $tbl) {
                $out .= '# ' . $dbEntry['database'] . '.' . $tbl['table'] . "\n";
                if (!empty($tbl['columns'])) {
                    $out .= $this->csvLine(array_column($tbl['columns'], 'name'));
                } elseif (!empty($tbl['rows'])) {
                    // includeStructure=false but includeData=true — derive headers from first row
                    $out .= $this->csvLine(array_keys($tbl['rows'][0]));
                }
                foreach ($tbl['rows'] as $row) {
                    $out .= $this->csvLine(array_values($row));
                }
                $out .= "\n";
            }
        }
        return $out;
    }

    private function csvLine(array $values): string
    {
        $buf = fopen('php://temp', 'r+');
        fputcsv($buf, array_map(fn($v) => $v === null ? '' : (string) $v, $values));
        rewind($buf);
        $line = stream_get_contents($buf);
        fclose($buf);
        return (string) $line;
    }
}
