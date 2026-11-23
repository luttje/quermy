<?php declare(strict_types=1);

namespace Quermy\Drivers;

use InvalidArgumentException;

/**
 * Validates the network connection parameters before they reach a driver.
 *
 * Every driver builds its PDO DSN by string concatenation, and a DSN is a
 * list of `key=value` pairs where a later key overrides an earlier one. A
 * value such as `x;host=10.0.0.5` in the database field would therefore
 * redirect the connection to another server — which in hosted mode defeats
 * the administrator's host/port lock. Only characters that cannot terminate
 * or extend a DSN segment are allowed through.
 */
final class ConnectionParams
{
    /**
     * Hostname, IPv4, bracketed or bare IPv6, or a SQL Server `host\instance`.
     *
     * @throws InvalidArgumentException
     */
    public static function host(mixed $host): string
    {
        if (!is_string($host) || !preg_match('/^[A-Za-z0-9._\-:\[\]\\\\]{1,255}$/D', $host)) {
            throw new InvalidArgumentException('Invalid host.');
        }
        return $host;
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function port(mixed $port): int
    {
        if (is_string($port) && ctype_digit($port)) {
            $port = (int)$port;
        }
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Invalid port.');
        }
        return $port;
    }

    /**
     * Database name for a network engine. Null or '' means "no default
     * database" and is returned as null.
     *
     * @throws InvalidArgumentException
     */
    public static function database(mixed $database): ?string
    {
        if ($database === null || $database === '') {
            return null;
        }
        if (!is_string($database) || !preg_match('/^[A-Za-z0-9_$.\-]{1,128}$/D', $database)) {
            throw new InvalidArgumentException(
                'Invalid database name. Only letters, digits, and _ $ . - are allowed here; '
                . 'leave the field empty and pick the database after connecting.'
            );
        }
        return $database;
    }

    /**
     * The username is passed to PDO as a separate argument, not through the
     * DSN, so it only needs to be a sane string.
     *
     * @throws InvalidArgumentException
     */
    public static function username(mixed $username): string
    {
        if (!is_string($username) || strlen($username) > 255 || preg_match('/[\x00-\x1F\x7F]/', $username)) {
            throw new InvalidArgumentException('Invalid username.');
        }
        return $username;
    }
}
