<?php declare(strict_types=1);

namespace Quermy\Ai\Tools;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    'suggest_query',
    'Suggests a SQL query for the user to review and execute via a "Run" button in the UI. This is the '
    . 'ONLY way you should propose SQL — never paste executable SQL into your chat response, as it bypasses '
    . 'the review step and confuses the user. Use this whenever the answer to a request requires running a '
    . 'query: data retrieval, aggregations, exact counts, schema inspection beyond what list_tables provides, '
    . 'or any DML/DDL the user has explicitly asked for. '
    . 'After calling this tool, your chat response should be a brief sentence or two — for example, '
    . '"I\'ve suggested a query that counts orders grouped by status. Review and click Run to execute." — '
    . 'and must NOT contain the SQL itself. '
    . 'Parameters: '
    . 'database (string) — the schema to run against; pass an empty string to use the user\'s current database. '
    . 'sql (string) — exactly ONE SQL statement; do not chain multiple statements with semicolons. Use '
    . 'quote identifiers using the correct syntax for the connected engine (e.g. backticks for MySQL/MariaDB, '
    . 'double-quotes for PostgreSQL/SQLite, square brackets for SQL Server), use explicit JOINs, '
    . 'and add ORDER BY whenever you use LIMIT or OFFSET. '
    . 'rationale (string) — a one-to-three sentence explanation shown to the user above the Run button. '
    . 'State what the query returns, any assumptions you made (e.g., "treats deleted_at IS NULL as active"), '
    . 'and flag if the query modifies data. For destructive statements (UPDATE, DELETE, DROP, etc.), the '
    . 'rationale MUST explicitly say so and describe the scope of the change.'
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
