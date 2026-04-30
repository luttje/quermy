<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Http\Route;
use Quermy\Http\Json;
use Quermy\Http\ConnectionSessionInterface;
use Quermy\Http\ServerConfig;
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
            $this->requireFields($body, ['database']);
            $config = ['database' => $body['database']];
        } else {
            $this->requireFields($body, ['host', 'port', 'username']);
            $config = [
                'host'     => $body['host'],
                'port'     => (int)$body['port'],
                'username' => $body['username'],
                'password' => (string)($body['password'] ?? ''),
                'database' => $body['database'] ?? null,
            ];
        }

        $driver = DriverFactory::make($body['engine']);
        $driver->connect($config);
        $driver->disconnect();

        $this->session->bindAdhoc($body);

        Json::send(['ok' => true]);
    }
}
