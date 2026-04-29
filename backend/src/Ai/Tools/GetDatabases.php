<?php declare(strict_types=1);

namespace Quermy\Ai\Tools;

use Quermy\Http\ConnectionSession;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('get_databases', 'Lists the databases / schemas visible on the user\'s currently connected server.')]
final class GetDatabases
{
    public function __construct(
        private ConnectionSession $session,
    ) {}

    /**
     * @return array{databases: list<string>}
     */
    public function __invoke(): array
    {
        $driver = $this->session->open();
        try {
            return ['databases' => $driver->listDatabases()];
        } finally {
            $driver->disconnect();
        }
    }
}
