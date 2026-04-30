<?php declare(strict_types=1);

namespace Quermy\Ai\Tools;

use Quermy\Drivers\Capabilities\SupportsGetCreateTable;
use Quermy\Http\ConnectionSessionInterface;
use RuntimeException;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    'get_create_table',
    'Returns the database\'s authoritative CREATE TABLE statement. This is the ground truth — it '
    . 'shows generated columns, character sets and collations, table-level options (ENGINE, '
    . 'AUTO_INCREMENT, ROW_FORMAT), partitioning, CHECK constraints, and the exact ordering of '
    . 'columns and indexes. '
    . 'Prefer describe_table for routine schema work; reach for this tool only when describe_table '
    . 'doesn\'t answer the question — for example when the user asks why a column auto-updates, '
    . 'what charset the table uses, whether a column is generated, or wants to replicate the table '
    . 'elsewhere. '
    . 'The returned DDL is a SQL statement, so per the usual rules do NOT paste it into your chat '
    . 'reply. If the user wants the DDL itself, use suggest_query with an appropriate engine-specific '
    . 'DDL statement so they can see and copy it through the suggestion UI. '
    . 'Parameters: '
    . 'database (string) — schema name. '
    . 'table (string) — table name. '
    . 'Returns: { ddl: string } — the full CREATE TABLE statement as a single string.'
)]
final class GetCreateTable
{
    public function __construct(
        private ConnectionSessionInterface $session,
    ) {}

    /**
     * @param string $database The schema containing the table.
     * @param string $table    The table whose DDL you want.
     *
     * @return array{ddl:string}
     */
    public function __invoke(string $database, string $table): array
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsGetCreateTable) {
                throw new RuntimeException('This engine does not support CREATE TABLE introspection.');
            }
            return ['ddl' => $driver->getCreateTable($database, $table)];
        } finally {
            $driver->disconnect();
        }
    }
}
