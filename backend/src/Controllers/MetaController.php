<?php declare(strict_types=1);

namespace Quermy\Controllers;

use Quermy\Drivers\DriverFactory;
use Quermy\Http\ConnectionSession;
use Quermy\Http\Json;
use Quermy\Http\Route;

final class MetaController extends BaseController
{
    public function __construct(
        private ConnectionSession $session,
    ) {}

    #[Route('GET', '/api/engines')]
    public function engines(): void
    {
        Json::send(['engines' => DriverFactory::supportedEngines()]);
    }

    #[Route('GET', '/api/session')]
    public function session(): void
    {
        Json::send(['active' => $this->session->describe()]);
    }

    #[Route('POST', '/api/session/disconnect')]
    public function disconnect(): void
    {
        $this->session->clear();
        Json::send(['ok' => true]);
    }
}
