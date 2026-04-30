<?php declare(strict_types=1);

namespace Quermy\Exporters;

use DOMDocument;

final class XmlExporter implements ExporterInterface
{
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

    public function export(array $export): string
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $root = $doc->createElement('export');
        $root->setAttribute('exporter', 'quermy');
        $root->setAttribute('generated', date('c'));
        $doc->appendChild($root);

        foreach ($export as $dbEntry) {
            $dbEl = $doc->createElement('database');
            $dbEl->setAttribute('name', $dbEntry['database']);
            $root->appendChild($dbEl);

            foreach ($dbEntry['tables'] as $tbl) {
                $tblEl = $doc->createElement('table');
                $tblEl->setAttribute('name', $tbl['table']);
                $dbEl->appendChild($tblEl);

                if (!empty($tbl['columns'])) {
                    $structEl = $doc->createElement('structure');
                    $tblEl->appendChild($structEl);
                    foreach ($tbl['columns'] as $col) {
                        $colEl = $doc->createElement('column');
                        foreach ($col as $k => $v) {
                            if (is_bool($v)) {
                                $v = $v ? 'true' : 'false';
                            }
                            $colEl->setAttribute((string) $k, $v === null ? '' : (string) $v);
                        }
                        $structEl->appendChild($colEl);
                    }
                }

                if (!empty($tbl['rows'])) {
                    $dataEl = $doc->createElement('data');
                    $tblEl->appendChild($dataEl);
                    foreach ($tbl['rows'] as $row) {
                        $rowEl = $doc->createElement('row');
                        $dataEl->appendChild($rowEl);
                        foreach ($row as $colName => $val) {
                            // XML element names must start with a letter/underscore.
                            $tag = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', (string) $colName);
                            $tag = ltrim((string) $tag, '.\-0123456789') ?: 'col';
                            $cellEl = $doc->createElement($tag);
                            if ($val === null) {
                                $cellEl->setAttribute('null', 'true');
                            } else {
                                $cellEl->appendChild($doc->createTextNode((string) $val));
                            }
                            $rowEl->appendChild($cellEl);
                        }
                    }
                }
            }
        }

        return (string) $doc->saveXML();
    }
}
