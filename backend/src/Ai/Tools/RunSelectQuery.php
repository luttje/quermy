<?php declare(strict_types=1);

namespace Quermy\Ai\Tools;

use Quermy\Http\ConnectionSession;
use RuntimeException;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

// TODO: This should not run directly on the database, instead it should pass by the user in the front-end for confirmation, since it can potentially run any query that the user could run.
// TODO: Then we want to change this from run_select_query to something like "suggest_query".
#[AsTool(
    'run_select_query',
    'Runs a read-only SELECT (or SHOW/DESCRIBE/EXPLAIN) query against a database and returns the rows. '
    . 'Use this to inspect data, confirm schema, or answer factual questions about the user\'s data. '
    . 'Mutating statements (INSERT/UPDATE/DELETE/DDL) are rejected — ask the user to run those themselves.'
)]
final class RunSelectQuery
{
    /** Statements we'll let the model execute on its own. */
    private const READ_ONLY_PREFIXES = ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'];

    /** Hard cap on rows returned to the model — protects the context window. */
    private const MAX_ROWS = 200;

    public function __construct(
        private ConnectionSession $session,
    ) {}

    /**
     * @param string $database The database to run against. Pass an empty string to use the current one.
     * @param string $sql      A single read-only SQL statement.
     *
     * @return array{
     *   columns: list<array{name:string,type:string}>,
     *   rows: list<array<string,mixed>>,
     *   rowCount: int,
     *   truncated: bool,
     *   durationMs: float
     * }
     */
    public function __invoke(string $database, string $sql): array
    {
        $sql = trim($sql);
        if ($sql === '') {
            throw new RuntimeException('SQL is empty.');
        }
        $this->assertReadOnly($sql);

        $driver = $this->session->open();
        try {
            $result    = $driver->runQuery($database, $sql);
            $rows      = $result['rows'];
            $truncated = false;
            if (count($rows) > self::MAX_ROWS) {
                $rows      = array_slice($rows, 0, self::MAX_ROWS);
                $truncated = true;
            }

            return [
                'columns'    => $result['columns'],
                'rows'       => $rows,
                'rowCount'   => $result['affected'],
                'truncated'  => $truncated,
                'durationMs' => $result['durationMs'],
            ];
        } finally {
            $driver->disconnect();
        }
    }

    private function assertReadOnly(string $sql): void
    {
        // Strip leading comments and whitespace, then check the first keyword.
        // This is a guardrail, not a security boundary — the driver still
        // runs with the user's DB credentials, so anything they could do
        // manually they could in principle convince the model to do too.
        // The point is to make it hard for the LLM to accidentally mutate
        // data while answering "how many users do I have?"-style questions.
        $stripped = preg_replace('@^(\s|/\*.*?\*/|--[^\n]*\n)+@s', '', $sql) ?? $sql;
        $first    = strtoupper(strtok($stripped, " \t\n\r;("));
        if (!in_array($first, self::READ_ONLY_PREFIXES, true)) {
            throw new RuntimeException(
                "Only read-only statements are allowed via this tool (got: $first). "
                . "Ask the user to run mutating statements themselves."
            );
        }
        // Reject multi-statement payloads outright.
        if (str_contains(rtrim($sql, "; \t\n\r"), ';')) {
            throw new RuntimeException('Only a single statement is allowed per call.');
        }
    }
}
