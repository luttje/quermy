<?php declare(strict_types=1);

namespace Quermy\Ai\Tools;

use Quermy\Http\ConnectionSession;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    'explain_query',
    'Runs EXPLAIN on a SELECT statement and returns the optimizer\'s execution plan, without '
    . 'running the query itself. This is your tool for the "Query optimization, EXPLAIN plans, and '
    . 'performance analysis" part of your job. '
    . 'When to use it: before suggesting a non-trivial query (multi-table JOIN, subquery, or filter '
    . 'on a non-indexed column) against a table that list_tables shows is large (>~100k rows). If '
    . 'EXPLAIN reports type "ALL" on a big table, key NULL on a JOIN, or a row-estimate '
    . 'orders-of-magnitude larger than what the user wants, revise the query — add an indexed '
    . 'predicate, change the join order, or note the limitation in the suggest_query rationale. '
    . 'When NOT to use it: small tables, the user explicitly asks for a one-off scan, the query is '
    . 'a simple SELECT by primary key, or you have already explained an equivalent query in this '
    . 'conversation. EXPLAIN is cheap but not free — don\'t loop on it. '
    . 'Read-only: only SELECT and WITH ... SELECT are accepted; the tool refuses anything else and '
    . 'rejects multi-statement payloads. '
    . 'Parameters: '
    . 'database (string) — schema to run against; pass "" to use the current one. '
    . 'sql (string) — a single SELECT (or WITH ... SELECT) statement. Do NOT prefix with EXPLAIN; '
    . 'this tool adds it. '
    . 'Returns: { plan: Array<Record<string,mixed>> } — one row per step in the query plan. '
    . 'The structure varies by database engine (e.g. MySQL returns EXPLAIN tabular rows, '
    . 'PostgreSQL returns JSON nodes, SQLite returns EXPLAIN QUERY PLAN rows).'
)]
final class ExplainQuery
{
    public function __construct(
        private ConnectionSession $session,
    ) {}

    /**
     * @param string $database The schema to run against (empty = current).
     * @param string $sql      A single SELECT statement.
     *
     * @return array{plan:list<array<string,mixed>>}
     */
    public function __invoke(string $database, string $sql): array
    {
        $sql = trim($sql);
        if ($sql === '') {
            throw new \RuntimeException('SQL is empty.');
        }
        // Cheap guard — also enforced in the driver, but rejecting early
        // gives the LLM a clearer error to recover from.
        if (!preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) {
            throw new \RuntimeException('explain_query only accepts SELECT (or WITH … SELECT) statements.');
        }

        $driver = $this->session->open();
        try {
            return ['plan' => $driver->explainQuery($database, $sql)];
        } finally {
            $driver->disconnect();
        }
    }
}
