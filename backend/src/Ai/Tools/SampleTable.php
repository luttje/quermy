<?php declare(strict_types=1);

namespace Quermy\Ai\Tools;

use Quermy\Http\ConnectionSession;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    'sample_table',
    'Returns a small unordered sample of rows from a table so you can see the actual shape of the '
    . 'data. Use this when column names alone don\'t tell you what\'s inside — to discover the '
    . 'real values in a "status" column (is it "active"/"inactive" or 1/0 or "A"/"I"?), to see how '
    . 'dates are formatted, to learn whether a "metadata" column contains JSON or plain text, or '
    . 'to check whether nominally nullable columns are populated in practice. Also reasonable when '
    . 'the user just wants to peek at a few rows. '
    . 'The sample is unordered and small, so it is NOT statistically representative — do not draw '
    . 'conclusions about distributions or trends from it, and do not report aggregates derived from '
    . 'it. For real data questions ("what statuses exist", "average order value", "how many active '
    . 'rows"), use suggest_query with an appropriate aggregate or DISTINCT. '
    . 'Parameters: '
    . 'database (string) — schema name. '
    . 'table (string) — table name. '
    . 'limit (int, optional) — number of rows to fetch, between 1 and 20. Default 5. Keep this small; '
    . 'a handful of rows is almost always enough to understand the shape. '
    . 'Returns: { columns: string[], rows: Array<Record<string,mixed>>, truncated: bool } where '
    . 'truncated is true when the sample hit the limit (which usually means more rows exist).'
)]
final class SampleTable
{
    public function __construct(
        private ConnectionSession $session,
    ) {}

    /**
     * @param string $database The schema containing the table.
     * @param string $table    The table to sample.
     * @param int    $limit    Rows to return (1–20). Default 5.
     *
     * @return array{columns:list<string>,rows:list<array<string,mixed>>,truncated:bool}
     */
    public function __invoke(string $database, string $table, int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));

        $driver = $this->session->open();
        try {
            return $driver->sampleTable($database, $table, $limit);
        } finally {
            $driver->disconnect();
        }
    }
}
