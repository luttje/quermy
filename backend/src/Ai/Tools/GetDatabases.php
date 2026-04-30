<?php declare(strict_types=1);

namespace Quermy\Ai\Tools;

use Quermy\Http\ConnectionSessionInterface;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    'get_databases',
    'Lists all non-system databases (schemas) visible on the connected database server. '
    . 'Use this when the user asks what databases exist, when you need to discover the correct '
    . 'database name before inspecting tables, or when the user\'s request references a database '
    . 'by an ambiguous name and you need to find the closest match. '
    . 'Do NOT call this if the user has already named a specific database — go directly to list_tables. '
    . 'Returns: { databases: string[] } — a flat list of schema names. Engine-specific system schemas '
    . 'are filtered out at the driver level and will not appear in the result.'
)]
final class GetDatabases
{
    public function __construct(
        private ConnectionSessionInterface $session,
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
