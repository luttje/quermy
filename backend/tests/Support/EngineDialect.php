<?php declare(strict_types=1);

namespace Tests\Support;

/**
 * Tiny value object that captures the per-engine syntax differences the
 * shared driver contract needs to vary on: identifier quoting and the
 * canonical "INT" / "VARCHAR" type names.
 */
final class EngineDialect
{
    public function __construct(
        public readonly string $identifierOpen,
        public readonly string $identifierClose,
        public readonly string $intType,
        public readonly string $varcharTemplate, // sprintf-style, e.g. "VARCHAR(%d)"
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
        return new self('`', '`', 'INT', 'VARCHAR(%d)');
    }

    public static function mariadb(): self
    {
        return self::mysql();
    }

    public static function postgresql(): self
    {
        return new self('"', '"', 'INTEGER', 'VARCHAR(%d)');
    }

    public static function sqlite(): self
    {
        return new self('"', '"', 'INTEGER', 'VARCHAR(%d)');
    }

    public static function sqlserver(): self
    {
        return new self('[', ']', 'INT', 'VARCHAR(%d)');
    }
}
