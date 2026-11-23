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

    public function export(iterable $export, $out): void
    {
        fwrite($out, "# Quermy CSV export\n# Generated: " . date('c') . "\n\n");

        foreach ($export as $dbEntry) {
            foreach ($dbEntry['tables'] as $tbl) {
                fwrite($out, '# ' . $dbEntry['database'] . '.' . $tbl['table'] . "\n");

                $headerWritten = false;
                if (!empty($tbl['columns'])) {
                    $this->csvLine($out, array_column($tbl['columns'], 'name'));
                    $headerWritten = true;
                }

                foreach ($tbl['rows'] as $row) {
                    if (!$headerWritten) {
                        // includeStructure=false but includeData=true — derive headers from first row
                        $this->csvLine($out, array_keys($row));
                        $headerWritten = true;
                    }
                    $this->csvLine($out, array_values($row));
                }

                fwrite($out, "\n");
            }
        }
    }

    /** @param resource $out */
    private function csvLine($out, array $values): void
    {
        fputcsv($out, array_map(fn($v) => $v === null ? '' : (string) $v, $values));
    }
}
