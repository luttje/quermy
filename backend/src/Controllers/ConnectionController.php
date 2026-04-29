<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Http\Route;
use Quermy\Http\Json;
use Quermy\Http\ConnectionSession;
use Quermy\Drivers\DriverFactory;

final class ConnectionController extends BaseController
{
    public function __construct(
        private ConnectionSession $session,
    ) {}

    #[Route('POST', '/api/connect')]
    public function connect(): void
    {
        $body = Json::readBody();
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
