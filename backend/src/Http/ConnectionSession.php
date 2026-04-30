<?php declare(strict_types=1);

namespace Quermy\Http;

use Quermy\Drivers\DriverFactory;
use Quermy\Drivers\DriverInterface;
use RuntimeException;

/**
 * PHP doesn't keep DB connections alive across requests, so each request
 * re-establishes the connection using credentials bound to the session.
 *
 * The session holds: { mode: 'adhoc', creds: {...} }
 *
 * Credentials (including plaintext passwords) live ONLY in $_SESSION, which
 * PHP stores server-side. They are never sent to the client.
 */
class ConnectionSession implements ConnectionSessionInterface
{
    /**
     * @inheritdoc ConnectionSessionInterface::bindAdhoc
     */
    public function bindAdhoc(array $creds): void
    {
        // Keep only what we need; null-safe for file-type connections
        // (SQLite) that have no host/port/username.
        $_SESSION['quermy_conn'] = [
            'mode'  => 'adhoc',
            'creds' => [
                'engine'   => $creds['engine'],
                'host'     => $creds['host']     ?? null,
                'port'     => isset($creds['port']) ? (int)$creds['port'] : null,
                'username' => $creds['username'] ?? null,
                'password' => (string)($creds['password'] ?? ''),
                'database' => $creds['database'] ?? null,
            ],
        ];
    }

    /**
     * @inheritdoc ConnectionSessionInterface::clear
     */
    public function clear(): void
    {
        unset($_SESSION['quermy_conn']);
    }

    /**
     * @inheritdoc ConnectionSessionInterface::isBound
     */
    public function isBound(): bool
    {
        return isset($_SESSION['quermy_conn']);
    }

    /**
     * @inheritdoc ConnectionSessionInterface::describe
     */
    public function describe(): ?array
    {
        if (!$this->isBound()) return null;
        $c = $_SESSION['quermy_conn']['creds'];
        return [
            'mode'     => 'adhoc',
            'engine'   => $c['engine'],
            'host'     => $c['host'],
            'port'     => $c['port'],
            'username' => $c['username'],
            'database' => $c['database'],
        ];
    }

    /**
     * @inheritdoc ConnectionSessionInterface::open
     */
    public function open(): DriverInterface
    {
        if (!$this->isBound()) {
            throw new RuntimeException('No active connection.');
        }
        $creds  = $_SESSION['quermy_conn']['creds'];
        $driver = DriverFactory::make($creds['engine']);
        $driver->connect($creds);
        return $driver;
    }
}
