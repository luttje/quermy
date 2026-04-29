<?php declare(strict_types=1);

namespace Quermy\Ai\Tools;

use Quermy\Http\ConnectionSession;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    'search_schema',
    'Searches across table names, column names, and their comments for a substring match within one '
    . 'database. Use this as a FALLBACK when you don\'t know where something lives — for example '
    . 'when the user refers to "the orders table" but list_tables shows tbl_order_hdr and order_lines '
    . 'and you need to pick the right one, or when the user mentions a field by its UI label and you '
    . 'need to find which table actually stores it. '
    . 'Do NOT call this when the user already named the table or the schema is small enough that '
    . 'list_tables makes the answer obvious. The match is a case-insensitive substring (LIKE %term%); '
    . 'pass a single discriminating term ("email", "tenant_id"), not a phrase. Results are capped '
    . 'at 100 tables and 200 columns — narrow your term if you hit the cap. '
    . 'Parameters: '
    . 'database (string) — schema to search within. '
    . 'term (string) — substring to look for; must be at least 2 characters. '
    . 'scope (string, optional) — "tables", "columns", or "all". Default "all". Use "tables" or '
    . '"columns" to cut noise when "all" returns too much. '
    . 'Returns: { '
    . 'tables: Array<{ name, comment }>, '
    . 'columns: Array<{ table, name, type, comment }> '
    . '}. Each list is empty when nothing matched in that scope or the scope was excluded.'
)]
final class SearchSchema
{
    public function __construct(
        private ConnectionSession $session,
    ) {}

    /**
     * @param string $database The schema to search.
     * @param string $term     Substring to look for (≥ 2 chars).
     * @param string $scope    "tables", "columns", or "all".
     *
     * @return array{
     *   tables: list<array{name:string,comment:string}>,
     *   columns: list<array{table:string,name:string,type:string,comment:string}>
     * }
     */
    public function __invoke(string $database, string $term, string $scope = 'all'): array
    {
        $term = trim($term);
        if (strlen($term) < 2) {
            throw new \RuntimeException('Search term must be at least 2 characters.');
        }
        if (!in_array($scope, ['tables', 'columns', 'all'], true)) {
            throw new \RuntimeException('Scope must be one of: tables, columns, all.');
        }

        $driver = $this->session->open();
        try {
            return $driver->searchSchema($database, $term, $scope);
        } finally {
            $driver->disconnect();
        }
    }
}
