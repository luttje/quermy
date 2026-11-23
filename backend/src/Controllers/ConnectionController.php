<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Http\Route;
use Quermy\Http\Json;
use Quermy\Http\ConnectionSessionInterface;
use Quermy\Http\ServerConfig;
use InvalidArgumentException;
use Quermy\Drivers\ConnectionParams;
use Quermy\Drivers\DriverFactory;

final class ConnectionController extends BaseController
{
    public function __construct(
        private ConnectionSessionInterface $session,
    ) {}

    #[Route('POST', '/api/connect')]
    public function connect(): void
    {
        $body = Json::readBody();

        // In hosted mode the administrator has fixed the engine, host, and port.
        // Override any user-supplied values with the server config so that
        // visitors can only reach the configured server, never an arbitrary one.
        $serverConn = ServerConfig::serverConnection();
        if ($serverConn !== null) {
            $body['engine'] = $serverConn['engine'];
            if ($serverConn['host'] !== null) $body['host'] = $serverConn['host'];
            if ($serverConn['port'] !== null) $body['port'] = $serverConn['port'];
            // Only override database when the admin has pinned one.
            if ($serverConn['database'] !== null && $serverConn['database'] !== '') {
                $body['database'] = $serverConn['database'];
            }
        }

        $this->requireFields($body, ['engine']);
        $meta = DriverFactory::engineMetaFor($body['engine']);

        if ($meta['connectionType'] === 'file') {
            // File engines read and write the server's filesystem, so they
            // are only available when the administrator has opted in.
            if (!ServerConfig::allowsFileEngines()) {
                Json::error(
                    $meta['label'] . " is disabled on this server. Set 'allow_sqlite' => true in config.php to enable it.",
                    403,
                );
            }
            $this->requireFields($body, ['database']);
            if (!is_string($body['database']) || str_contains($body['database'], "\0")) {
                Json::error('Invalid database path.', 422);
            }
            $config = ['database' => $body['database']];
        } else {
            $this->requireFields($body, ['host', 'port', 'username']);

            // These values are concatenated into the PDO DSN by the drivers,
            // so they must not be able to smuggle in extra DSN keys. The
            // validated values are written back to $body because that is
            // what gets bound to the session below.
            try {
                $body['host']     = ConnectionParams::host($body['host']);
                $body['port']     = ConnectionParams::port($body['port']);
                $body['username'] = ConnectionParams::username($body['username']);
                $body['database'] = ConnectionParams::database($body['database'] ?? null);
            } catch (InvalidArgumentException $e) {
                Json::error($e->getMessage(), 422);
            }
            $body['password'] = is_scalar($body['password'] ?? '') ? (string)($body['password'] ?? '') : '';

            $config = [
                'host'     => $body['host'],
                'port'     => $body['port'],
                'username' => $body['username'],
                'password' => $body['password'],
                'database' => $body['database'],
            ];
        }

        $driver = DriverFactory::make($body['engine']);
        $driver->connect($config);
        $driver->disconnect();

        $this->session->bindAdhoc($body);

        Json::send(['ok' => true]);
    }
}
