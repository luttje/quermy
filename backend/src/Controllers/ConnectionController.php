<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Http\Route;
use Quermy\Http\Json;
use Quermy\Http\ConnectionSession;
use Quermy\Storage\CredentialVault;
use Quermy\Drivers\DriverFactory;

final class ConnectionController extends BaseController
{
    public function __construct(
        private CredentialVault $vault,
        private ConnectionSession $session,
    ) {}

    #[Route('GET', '/api/connections')]
    public function list(): void
    {
        Json::send(['connections' => $this->vault->listPublic()]);
    }

    #[Route('POST', '/api/connections')]
    public function create(): void
    {
        $body = Json::readBody();
        $this->requireFields($body, ['engine','host','port','username']);
        Json::send(['connection' => $this->vault->save($body)], 201);
    }

    #[Route('DELETE', '/api/connections/{id}')]
    public function delete(string $id): void
    {
        $ok = $this->vault->delete($id);
        Json::send(['ok' => $ok], $ok ? 200 : 404);
    }

    #[Route('POST', '/api/connect')]
    public function connect(): void
    {
        $body = Json::readBody();
        $this->requireFields($body, ['engine','host','port','username']);

        $driver = DriverFactory::make($body['engine']);
        $driver->connect([
            'host'     => $body['host'],
            'port'     => (int)$body['port'],
            'username' => $body['username'],
            'password' => (string)($body['password'] ?? ''),
            'database' => $body['database'] ?? null,
        ]);
        $driver->disconnect();

        $savedRecord = null;
        if (!empty($body['save'])) {
            $savedRecord = $this->vault->save($body);
            $this->session->bindSaved($savedRecord['id']);
        } else {
            $this->session->bindAdhoc($body);
        }

        Json::send(['ok' => true, 'saved' => $savedRecord]);
    }

    #[Route('POST', '/api/connect/saved/{id}')]
    public function connectSaved(string $id): void
    {
        $creds = $this->vault->loadCredentials($id);
        if (!$creds) Json::error('Connection not found', 404);
        $driver = DriverFactory::make($creds['engine']);
        $driver->connect($creds);
        $driver->disconnect();
        $this->session->bindSaved($id);
        Json::send(['ok' => true]);
    }
}
