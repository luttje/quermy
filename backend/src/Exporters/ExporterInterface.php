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
 *   iterable<array{
 *     database: string,
 *     tables: iterable<array{
 *       table:   string,
 *       ddl:     string|null,                    // CREATE TABLE, when available
 *       columns: list<array<string,mixed>>,      // empty when structure excluded
 *       rows:    iterable<array<string,mixed>>,  // empty when data excluded
 *     }>
 *   }>
 *
 * `rows` may be a generator that fetches from the database page by page, so
 * a table is never held in memory as a whole. Implementations must therefore
 * walk it exactly once, front to back, and write as they go — no count(),
 * no indexing, no second pass.
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
     * Write the export to a stream, piece by piece.
     *
     * @param iterable<array{database:string,tables:iterable<array{table:string,ddl?:string|null,columns:list<array<string,mixed>>,rows:iterable<array<string,mixed>>}>}> $export
     * @param resource $out  Writable stream.
     */
    public function export(iterable $export, $out): void;
}
