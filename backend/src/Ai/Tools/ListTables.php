<?php declare(strict_types=1);

namespace Quermy\Ai\Tools;

use Quermy\Http\ConnectionSession;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('list_tables', 'Lists the tables in a given database, with estimated row counts and sizes when available. When the user asks for a specific table row count, use the "run_select_query" tool instead to get an exact count.')]
final class ListTables
{
    public function __construct(
        private ConnectionSession $session,
    ) {}

    /**
     * @param string $database The database/schema name to inspect.
     *
     * @return array{tables: list<array{name:string,rows:int|null,size:int|null}>}
     */
    public function __invoke(string $database): array
    {
        $driver = $this->session->open();
        try {
            return ['tables' => $driver->listTables($database)];
        } finally {
            $driver->disconnect();
        }
    }
}
