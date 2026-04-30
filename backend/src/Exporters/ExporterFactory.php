<?php declare(strict_types=1);

namespace Quermy\Exporters;

use RuntimeException;

class ExporterFactory
{
    /** @var array<string,class-string<ExporterInterface>> */
    private static array $exporters = [
        'csv'  => SingleCsvExporter::class,
        'sql'  => SqlExporter::class,
        'xml'  => XmlExporter::class,
        'json' => JsonExporter::class,
    ];

    /**
     * @param array{
     *   caps: array<string, mixed>,
     *   includeStructure?: bool,
     *   includeData?: bool,
     * } $options  Format-specific context (currently only consumed by SqlExporter).
     */
    public static function make(string $format, array $options = []): ExporterInterface
    {
        if (!isset(self::$exporters[$format])) {
            throw new RuntimeException("Unsupported export format: $format");
        }

        return match ($format) {
            'sql' => new SqlExporter(
                $options['caps'],
                (bool) ($options['includeStructure'] ?? true),
                (bool) ($options['includeData'] ?? true),
            ),
            default => new (self::$exporters[$format])(),
        };
    }

    /** @return list<string> */
    public static function supportedFormats(): array
    {
        return array_keys(self::$exporters);
    }
}
