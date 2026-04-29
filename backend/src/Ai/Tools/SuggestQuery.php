<?php declare(strict_types=1);

namespace Quermy\Ai\Tools;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    'suggest_query',
    'Suggests a MySQL query for the user to review and run. '
    . 'Use this whenever you want to retrieve data from the database, or when the user asks you to run a query '
    . 'directly, you return it here so the user can confirm it first. The query will be shown to the user with a "Run" button. '
    . 'After calling this, simply respond with a normal message along the lines of '
    . '"I have suggested a query to run, please review it and click Run to see the results." (do NOT include the SQL in your response)'
)]
final class SuggestQuery
{
    /**
     * @param string $database  The database to run the query against. Pass an empty string to use the current one.
     * @param string $sql       A single SQL statement.
     * @param string $rationale A short human-readable explanation of what this query does and why
     *                          you are suggesting it. Shown to the user above the Run button.
     *
     * @return array{suggested: true, database: string, sql: string, rationale: string}
     */
    public function __invoke(string $database, string $sql, string $rationale = ''): array
    {
        $sql = trim($sql);
        if ($sql === '') {
            throw new \RuntimeException('SQL is empty.');
        }

        // We intentionally do NOT execute the query — we return it so the
        // frontend can render a "Run" button for the user to confirm.
        return [
            'suggested' => true,
            'database'  => $database,
            'sql'       => $sql,
            'rationale' => $rationale,
        ];
    }
}
