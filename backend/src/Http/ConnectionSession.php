<?php declare(strict_types=1);

namespace Quermy\Http;

use Quermy\Drivers\DriverFactory;
use Quermy\Drivers\DriverInterface;
use Quermy\Storage\CredentialVault;
use RuntimeException;

/**
 * PHP doesn't keep DB connections alive across requests, so each request
 * re-establishes the connection using credentials bound to the session.
 *
 * The session holds either:
 *   - { mode: 'saved', connectionId: '...' }    — refers to vault entry
 *   - { mode: 'adhoc', creds: {...} }           — typed in but not saved
 *
 * Plaintext passwords for adhoc connections live ONLY in $_SESSION which
 * PHP stores server-side. They are never sent to the client.
 */
class ConnectionSession
{
    public function __construct(private CredentialVault $vault) {}

    public function bindSaved(string $connectionId): void
    {
        $_SESSION['quermy_conn'] = ['mode' => 'saved', 'connectionId' => $connectionId];
    }

    public function bindAdhoc(array $creds): void
    {
        // Keep only what we need.
        $_SESSION['quermy_conn'] = [
            'mode'  => 'adhoc',
            'creds' => [
                'engine'   => $creds['engine'],
                'host'     => $creds['host'],
                'port'     => (int)$creds['port'],
                'username' => $creds['username'],
                'password' => (string)($creds['password'] ?? ''),
                'database' => $creds['database'] ?? null,
            ],
        ];
    }

    public function clear(): void
    {
        unset($_SESSION['quermy_conn']);
    }

    public function isBound(): bool
    {
        return isset($_SESSION['quermy_conn']);
    }

    public function describe(): ?array
    {
        if (!$this->isBound()) return null;
        $bind = $_SESSION['quermy_conn'];
        if ($bind['mode'] === 'saved') {
            $creds = $this->vault->loadCredentials($bind['connectionId']);
            if (!$creds) return null;
            return [
                'mode'     => 'saved',
                'id'       => $creds['id'],
                'engine'   => $creds['engine'],
                'host'     => $creds['host'],
                'port'     => $creds['port'],
                'username' => $creds['username'],
                'database' => $creds['database'],
            ];
        }
        $c = $bind['creds'];
        return [
            'mode'     => 'adhoc',
            'engine'   => $c['engine'],
            'host'     => $c['host'],
            'port'     => $c['port'],
            'username' => $c['username'],
            'database' => $c['database'],
        ];
    }

    /** Open a fresh driver for this request using the bound credentials. */
    public function open(): DriverInterface
    {
        if (!$this->isBound()) {
            throw new RuntimeException('No active connection.');
        }
        $bind = $_SESSION['quermy_conn'];
        if ($bind['mode'] === 'saved') {
            $creds = $this->vault->loadCredentials($bind['connectionId']);
            if (!$creds) {
                throw new RuntimeException('Saved connection no longer exists.');
            }
        } else {
            $creds = $bind['creds'];
        }
        $driver = DriverFactory::make($creds['engine']);
        $driver->connect($creds);
        return $driver;
    }
}
