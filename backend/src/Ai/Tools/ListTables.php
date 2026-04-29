<?php declare(strict_types=1);

namespace Quermy\Ai\Tools;

use Quermy\Http\ConnectionSession;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    'list_tables',
    'Lists tables in a given database along with estimated row counts and approximate sizes in bytes. '
    . 'Use this to discover what tables exist before constructing a query, to find a table whose exact '
    . 'name you do not know, or to give the user a high-level overview of a schema. '
    . 'The row counts and sizes come from MySQL\'s information_schema and are ESTIMATES — they can be '
    . 'significantly off for InnoDB tables and should never be reported to the user as exact figures. '
    . 'If the user asks for a precise row count for a specific table, use suggest_query with '
    . 'SELECT COUNT(*) instead. The size estimate is, however, accurate enough to decide whether a '
    . 'table is "large" for the purposes of explain_query and the large-result-set guidance — treat '
    . 'tables with rows >~100k as candidates for EXPLAIN before suggesting non-trivial queries. '
    . 'Once you know the target table, follow up with describe_table for column details, '
    . 'get_foreign_keys for join paths, or sample_table to see actual data shape. '
    . 'Parameter: database (string) — the schema name to inspect; must match an existing database exactly. '
    . 'Returns: { tables: Array<{ name: string, rows: int|null, size: int|null }> } where rows is the '
    . 'estimated row count, size is the estimated size in bytes, and either may be null if unavailable. '
    . 'Only base tables are returned — views are excluded.'
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
