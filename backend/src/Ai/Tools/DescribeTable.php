<?php declare(strict_types=1);

namespace Quermy\Ai\Tools;

use Quermy\Http\ConnectionSession;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    'describe_table',
    'Returns the full structure of a table: every column with type, nullability, default, key role, and '
    . 'comment, plus the primary key and all secondary indexes. '
    . 'Call this when you are about to write a query that references specific columns of a table — '
    . 'column naming conventions vary too widely across schemas to guess (created_at vs createdAt vs '
    . 'date_created, id vs <table>_id). Also call it when the user asks "what fields does X have", or '
    . 'when you need the primary key to write a targeted UPDATE/DELETE. Skip this call if you have '
    . 'already seen the columns from sample_table or get_create_table in this conversation. '
    . 'For composing JOINs, follow up with get_foreign_keys — describe_table only shows that a column '
    . 'has a key role ("MUL"), not what it references. '
    . 'Throws if the table does not exist or is not accessible — surface that error to the user and '
    . 'reconsider the table name (search_schema can help find the right one). '
    . 'Parameters: '
    . 'database (string) — schema name; must match exactly. '
    . 'table (string) — table name within that schema; must match exactly. '
    . 'Returns: { '
    . 'columns: Array<{ name, type, nullable, key, default, extra, comment }>, '
    . 'primaryKey: string[], '
    . 'indexes: Array<{ name, columns: string[], unique: bool }> '
    . '}. The per-column "key" field is "PRI", "UNI", "MUL", or "". "extra" surfaces things like '
    . '"auto_increment" or "on update CURRENT_TIMESTAMP". The top-level "indexes" list is the source '
    . 'of truth for which WHERE/ORDER BY columns will be efficient.'
)]
final class DescribeTable
{
    public function __construct(
        private ConnectionSession $session,
    ) {}

    /**
     * @param string $database The schema containing the table.
     * @param string $table    The table to describe.
     *
     * @return array{
     *   columns: list<array{name:string,type:string,nullable:bool,key:string,default:mixed,extra:string,comment:string}>,
     *   primaryKey: list<string>,
     *   indexes: list<array{name:string,columns:list<string>,unique:bool}>
     * }
     */
    public function __invoke(string $database, string $table): array
    {
        $driver = $this->session->open();
        try {
            return $driver->describeTable($database, $table);
        } finally {
            $driver->disconnect();
        }
    }
}
