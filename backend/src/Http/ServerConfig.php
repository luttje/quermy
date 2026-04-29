<?php declare(strict_types=1);

namespace Quermy\Http;

/**
 * Reads the optional server-managed connection configuration from
 * backend/config.php.
 *
 * When present, Quermy operates in "hosted mode": the engine, host, and port
 * are fixed by the administrator and visitors only supply credentials.
 */
final class ServerConfig
{
    private static ?array $loaded = null;

    private static function data(): array
    {
        if (self::$loaded !== null) {
            return self::$loaded;
        }

        // backend/config.php sits two levels above this file (src/Http/).
        $path = __DIR__ . '/../../config.php';
        self::$loaded = (is_file($path) && is_readable($path))
            ? (array)(require $path)
            : [];

        return self::$loaded;
    }

    /**
     * Returns the server-managed connection parameters, or null when the
     * instance has not been configured for hosted mode.
     *
     * @return array{engine:string,host:string|null,port:int|null,database:string|null}|null
     */
    public static function serverConnection(): ?array
    {
        $cfg = self::data()['server_connection'] ?? null;
        if (!is_array($cfg) || empty($cfg['engine'])) {
            return null;
        }

        return [
            'engine'   => (string)$cfg['engine'],
            'host'     => isset($cfg['host'])     ? (string)$cfg['host']    : null,
            'port'     => isset($cfg['port'])      ? (int)$cfg['port']       : null,
            'database' => isset($cfg['database'])  ? (string)$cfg['database'] : null,
        ];
    }

    /** True when a server_connection block is configured. */
    public static function isHostedMode(): bool
    {
        return self::serverConnection() !== null;
    }
}
