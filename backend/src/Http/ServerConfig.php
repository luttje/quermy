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

    /**
     * Whether file-based engines (SQLite) may be used.
     *
     * Off unless config.php sets 'allow_sqlite' => true. A SQLite connection
     * opens a path on the *server's* filesystem and, through ATTACH DATABASE,
     * can create files anywhere PHP may write — so it must be an explicit
     * decision by whoever runs the instance.
     */
    public static function allowsFileEngines(): bool
    {
        return (self::data()['allow_sqlite'] ?? false) === true;
    }

    /**
     * Hostnames this instance may be addressed by ('allowed_hosts'), or null
     * when not configured. See RequestGuard::hostAllowed().
     *
     * @return list<string>|null
     */
    public static function allowedHosts(): ?array
    {
        $hosts = self::data()['allowed_hosts'] ?? null;
        return is_array($hosts) ? array_values(array_filter($hosts, 'is_string')) : null;
    }

    /**
     * Extra origins allowed to call the API cross-origin with credentials
     * ('dev_cors_origins'). Empty unless a developer opts in.
     *
     * @return list<string>
     */
    public static function devCorsOrigins(): array
    {
        $origins = self::data()['dev_cors_origins'] ?? [];
        return is_array($origins) ? array_values(array_filter($origins, 'is_string')) : [];
    }

    /** True when a server_connection block is configured. */
    public static function isHostedMode(): bool
    {
        return self::serverConnection() !== null;
    }
}
