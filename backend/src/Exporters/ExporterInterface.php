<?php declare(strict_types=1);

namespace Quermy\Exporters;

/**
 * Contract that every export-format strategy must implement.
 *
 * Adding a new format (e.g. YAML, Excel) is a matter of writing one class
 * that implements this interface and registering it in ExporterFactory.
 * Nothing else in the codebase needs to change.
 *
 * Implementations receive a normalised export structure of the shape:
 *
 *   list<array{
 *     database: string,
 *     tables: list<array{
 *       table:   string,
 *       columns: list<array<string,mixed>>,  // empty when structure excluded
 *       rows:    list<array<string,mixed>>,  // empty when data excluded
 *     }>
 *   }>
 */
interface ExporterInterface
{
    /** Identifier used in the API (e.g. "csv", "sql"). */
    public static function formatId(): string;

    /** MIME type to send in the Content-Type header. */
    public function contentType(): string;

    /** File extension (without dot) for the download filename. */
    public function extension(): string;

    /**
     * Render the export structure as a single string payload.
     *
     * @param list<array{database:string,tables:list<array{table:string,columns:list<array<string,mixed>>,rows:list<array<string,mixed>>}>}> $export
     */
    public function export(array $export): string;
}
