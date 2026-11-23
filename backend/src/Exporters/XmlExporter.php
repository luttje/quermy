<?php declare(strict_types=1);

namespace Quermy\Exporters;

use XMLWriter;

/**
 * Writes the export as XML.
 *
 * Uses XMLWriter rather than building a DOM tree, because a DOM tree holds
 * the entire document in memory. The writer's buffer is emptied into the
 * output stream after every table header and every batch of rows.
 */
final class XmlExporter implements ExporterInterface
{
    private const FLUSH_EVERY_ROWS = 500;

    public static function formatId(): string
    {
        return 'xml';
    }

    public function contentType(): string
    {
        return 'application/xml';
    }

    public function extension(): string
    {
        return 'xml';
    }

    public function export(iterable $export, $out): void
    {
        $w = new XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'UTF-8');

        $w->startElement('export');
        $w->writeAttribute('exporter', 'quermy');
        $w->writeAttribute('generated', date('c'));

        foreach ($export as $dbEntry) {
            $w->startElement('database');
            $w->writeAttribute('name', (string) $dbEntry['database']);

            foreach ($dbEntry['tables'] as $tbl) {
                $w->startElement('table');
                $w->writeAttribute('name', (string) $tbl['table']);

                if (!empty($tbl['columns'])) {
                    $w->startElement('structure');
                    foreach ($tbl['columns'] as $col) {
                        $w->startElement('column');
                        foreach ($col as $k => $v) {
                            if (is_bool($v)) {
                                $v = $v ? 'true' : 'false';
                            }
                            $w->writeAttribute((string) $k, $v === null ? '' : (string) $v);
                        }
                        $w->endElement();
                    }
                    $w->endElement();
                }
                fwrite($out, $w->flush(true));

                $rowCount = 0;
                foreach ($tbl['rows'] as $row) {
                    if ($rowCount === 0) {
                        $w->startElement('data');
                    }

                    $w->startElement('row');
                    foreach ($row as $colName => $val) {
                        // XML element names must start with a letter/underscore.
                        $tag = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', (string) $colName);
                        $tag = ltrim((string) $tag, '.\-0123456789') ?: 'col';

                        $w->startElement($tag);
                        if ($val === null) {
                            $w->writeAttribute('null', 'true');
                        } else {
                            $w->text((string) $val);
                        }
                        $w->endElement();
                    }
                    $w->endElement();

                    if (++$rowCount % self::FLUSH_EVERY_ROWS === 0) {
                        fwrite($out, $w->flush(true));
                    }
                }
                if ($rowCount > 0) {
                    $w->endElement(); // data
                }

                $w->endElement(); // table
                fwrite($out, $w->flush(true));
            }

            $w->endElement(); // database
        }

        $w->endElement(); // export
        $w->endDocument();
        fwrite($out, $w->flush(true));
    }
}
