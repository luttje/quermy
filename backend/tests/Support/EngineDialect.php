<?php declare(strict_types=1);

namespace Tests\Support;

/**
 * Tiny value object that captures the per-engine syntax differences the
 * shared driver contract needs to vary on: identifier quoting, the
 * canonical "INT" / "VARCHAR" type names, and which optional schema
 * features the engine exposes.
 */
final class EngineDialect
{
    public function __construct(
        public readonly string $identifierOpen,
        public readonly string $identifierClose,
        public readonly string $intType,
        public readonly string $varcharTemplate, // sprintf-style, e.g. "VARCHAR(%d)"
        /**
         * Whether the engine stores and returns column-level comments.
         * MySQL/MariaDB support COMMENT '…' on column definitions and expose
         * them through information_schema; PostgreSQL uses pg_description;
         * SQLite has no comment concept at all, so the driver returns '' but
         * the column key is still present in the result array — set this to
         * false only when the key itself is absent.
         */
        public readonly bool $supportsColumnComments = true,
    ) {}

    public function quote(string $ident): string
    {
        // Defensive escaping — for tests this is overkill but avoids
        // surprises if someone passes a name containing the close quote.
        $escaped = str_replace($this->identifierClose, $this->identifierClose . $this->identifierClose, $ident);
        return $this->identifierOpen . $escaped . $this->identifierClose;
    }

    public function varchar(int $length): string
    {
        return sprintf($this->varcharTemplate, $length);
    }

    public static function mysql(): self
    {
        return new self('`', '`', 'INT', 'VARCHAR(%d)', supportsColumnComments: true);
    }

    public static function mariadb(): self
    {
        return self::mysql();
    }

    public static function postgresql(): self
    {
        return new self('"', '"', 'INTEGER', 'VARCHAR(%d)', supportsColumnComments: true);
    }

    public static function sqlite(): self
    {
        // SQLite has no comment syntax; the driver omits the 'comment' key entirely.
        return new self('"', '"', 'INTEGER', 'VARCHAR(%d)', supportsColumnComments: false);
    }

    public static function sqlserver(): self
    {
        return new self('[', ']', 'INT', 'VARCHAR(%d)', supportsColumnComments: true);
    }
}
