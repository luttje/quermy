<?php declare(strict_types=1);

namespace Quermy\Ai\Tools;

use Quermy\Http\ConnectionSession;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    'list_tables',
    'Lists tables in a given database along with estimated row counts and approximate sizes in bytes. '
    . 'Use this to discover what tables exist before constructing a query, to find a table whose exact '
    . 'name you do not know, or to give the user a high-level overview of a schema. The row counts and '
    . 'sizes come from MySQL\'s information_schema and are ESTIMATES — they can be significantly off for '
    . 'InnoDB tables and should never be reported to the user as exact figures. If the user asks for a '
    . 'precise row count for a specific table, use suggest_query with SELECT COUNT(*) instead. '
    . 'Parameter: database (string) — the schema name to inspect; must match an existing database exactly. '
    . 'Returns: { tables: Array<{ name: string, rows: int|null, size: int|null }> } where rows is the '
    . 'estimated row count, size is the estimated size in bytes, and either may be null if unavailable.'
)]
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
