<?php declare(strict_types=1);

namespace Quermy\Exporters;

final class SqlExporter implements ExporterInterface
{
    /**
     * @param array<string, mixed>     $caps              Driver capabilities (used to determine quoting style).
     * @param bool                     $includeStructure  Emit DROP/CREATE TABLE statements.
     * @param bool                     $includeData       Emit INSERT statements.
     */
    public function __construct(
        private array $caps,
        private readonly bool $includeStructure,
        private readonly bool $includeData,
    ) {}

    public static function formatId(): string
    {
        return 'sql';
    }

    public function contentType(): string
    {
        return 'text/plain';
    }

    public function extension(): string
    {
        return 'sql';
    }

    public function export(array $export): string
    {
        $qi  = $this->makeQuoter(
            (string) ($this->caps['identifierOpen']  ?? '`'),
            (string) ($this->caps['identifierClose'] ?? '`')
        );
        $out = "-- Quermy SQL export\n-- Generated: " . date('c') . "\n\n";

        foreach ($export as $dbEntry) {
            $db  = $dbEntry['database'];
            $out .= '-- Database: ' . $qi($db) . "\n\n";

            foreach ($dbEntry['tables'] as $tbl) {
                $table   = $tbl['table'];
                $columns = $tbl['columns'];

                if ($this->includeStructure && isset($tbl['ddl']) && $tbl['ddl'] !== null) {
                    $out .= 'DROP TABLE IF EXISTS ' . $qi($db) . '.' . $qi($table) . ";\n";
                    $out .= $tbl['ddl'] . ";\n\n";
                }

                if ($this->includeData && !empty($tbl['rows'])) {
                    $colNames = implode(
                        ', ',
                        !empty($columns)
                            ? array_map(fn($c) => $qi($c['name']), $columns)
                            : array_map($qi, array_keys($tbl['rows'][0]))
                    );
                    foreach ($tbl['rows'] as $row) {
                        $vals = implode(', ', array_map(
                            fn($v) => $v === null ? 'NULL' : "'" . addslashes((string) $v) . "'",
                            array_values($row)
                        ));
                        $out .= 'INSERT INTO ' . $qi($db) . '.' . $qi($table) . " ({$colNames}) VALUES ({$vals});\n";
                    }
                    $out .= "\n";
                }
            }
        }

        return $out;
    }

    /**
     * Returns a callable that quotes a single identifier with the engine's
     * delimiter pair (e.g. backtick for MySQL, double-quote for PostgreSQL).
     *
     * @return callable(string): string
     */
    private function makeQuoter(string $open, string $close): callable
    {
        return static function (string $name) use ($open, $close): string {
            return $open . str_replace($close, $close . $close, $name) . $close;
        };
    }
}
