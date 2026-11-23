<?php declare(strict_types=1);

namespace Quermy\Exporters;

/**
 * Writes the export as pretty-printed JSON.
 *
 * json_encode() needs the whole value in memory, so the document's skeleton
 * is written by hand and only the small pieces (a column list, one row) go
 * through json_encode(). The result is laid out the same way
 * JSON_PRETTY_PRINT would lay out the whole document.
 */
final class JsonExporter implements ExporterInterface
{
    private const FLAGS  = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;
    private const INDENT = '    ';

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

    public function export(iterable $export, $out): void
    {
        fwrite($out, "{\n");
        fwrite($out, self::INDENT . '"exporter": "quermy",' . "\n");
        fwrite($out, self::INDENT . '"generated": ' . $this->encode(date('c'), 1) . ",\n");
        fwrite($out, self::INDENT . '"databases": {');

        $firstDb = true;
        foreach ($export as $dbEntry) {
            fwrite($out, ($firstDb ? '' : ',') . "\n");
            $firstDb = false;

            fwrite($out, $this->pad(2) . $this->encode((string) $dbEntry['database'], 2) . ": {\n");
            fwrite($out, $this->pad(3) . '"tables": {');

            $firstTable = true;
            foreach ($dbEntry['tables'] as $tbl) {
                fwrite($out, ($firstTable ? '' : ',') . "\n");
                $firstTable = false;
                $this->writeTable($out, $tbl);
            }

            fwrite($out, ($firstTable ? '' : "\n" . $this->pad(3)) . "}\n");
            fwrite($out, $this->pad(2) . '}');
        }

        fwrite($out, ($firstDb ? '' : "\n" . self::INDENT) . "}\n");
        fwrite($out, '}');
    }

    /**
     * @param resource $out
     * @param array{table:string,columns:list<array<string,mixed>>,rows:iterable<array<string,mixed>>} $tbl
     */
    private function writeTable($out, array $tbl): void
    {
        fwrite($out, $this->pad(4) . $this->encode((string) $tbl['table'], 4) . ': {');

        // "columns" and "rows" are each left out when empty.
        $hasMember = false;

        if (!empty($tbl['columns'])) {
            fwrite($out, "\n" . $this->pad(5) . '"columns": ' . $this->encode($tbl['columns'], 5));
            $hasMember = true;
        }

        $firstRow = true;
        foreach ($tbl['rows'] as $row) {
            if ($firstRow) {
                fwrite($out, ($hasMember ? ',' : '') . "\n" . $this->pad(5) . '"rows": [');
                $hasMember = true;
            }
            fwrite($out, ($firstRow ? '' : ',') . "\n" . $this->pad(6) . $this->encode($row, 6));
            $firstRow = false;
        }
        if (!$firstRow) {
            fwrite($out, "\n" . $this->pad(5) . ']');
        }

        fwrite($out, ($hasMember ? "\n" . $this->pad(4) : '') . '}');
    }

    /** Encode a value, indenting its continuation lines to sit at $level. */
    private function encode(mixed $value, int $level): string
    {
        $json = (string) json_encode($value, self::FLAGS);
        return str_replace("\n", "\n" . $this->pad($level), $json);
    }

    private function pad(int $level): string
    {
        return str_repeat(self::INDENT, $level);
    }
}
