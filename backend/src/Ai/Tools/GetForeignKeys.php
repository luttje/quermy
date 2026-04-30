<?php declare(strict_types=1);

namespace Quermy\Ai\Tools;

use Quermy\Drivers\Capabilities\SupportsForeignKeys;
use Quermy\Http\ConnectionSessionInterface;
use RuntimeException;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool(
    'get_foreign_keys',
    'Returns all foreign key relationships involving a table — both outgoing (this table\'s columns '
    . 'that reference other tables) and incoming (other tables\' columns that reference this one). '
    . 'Call this whenever a query needs a JOIN: it is the only reliable way to discover real join '
    . 'paths. Inferring "users.id = orders.user_id" from naming conventions is a guess; this tool '
    . 'gives you the answer the database itself enforces. Also use it before suggesting a DELETE on '
    . 'a parent table — incoming FKs reveal what cascades or what will fail with a constraint error. '
    . 'If both sides return empty, the schema may not declare FKs explicitly (older MyISAM tables, '
    . 'or schemas relying on application-level integrity). In that case, fall back to inferring '
    . 'joins from naming conventions (<other_table>_id) and confirm by calling describe_table on '
    . 'both tables to verify the column types match. '
    . 'Parameters: '
    . 'database (string) — schema name. '
    . 'table (string) — table to inspect. '
    . 'Returns: { '
    . 'outgoing: Array<{ column, referencedDatabase, referencedTable, referencedColumn, constraintName, onUpdate, onDelete }>, '
    . 'incoming: Array<{ column, referencingDatabase, referencingTable, referencingColumn, constraintName, onUpdate, onDelete }> '
    . '}. onUpdate/onDelete are the referential actions: "CASCADE", "SET NULL", "RESTRICT", '
    . '"NO ACTION". Pay attention to these before suggesting destructive operations.'
)]
final class GetForeignKeys
{
    public function __construct(
        private ConnectionSessionInterface $session,
    ) {}

    /**
     * @param string $database The schema containing the table.
     * @param string $table    The table whose relationships you want.
     *
     * @return array{
     *   outgoing: list<array{column:string,referencedDatabase:string,referencedTable:string,referencedColumn:string,constraintName:string,onUpdate:string,onDelete:string}>,
     *   incoming: list<array{column:string,referencingDatabase:string,referencingTable:string,referencingColumn:string,constraintName:string,onUpdate:string,onDelete:string}>
     * }
     */
    public function __invoke(string $database, string $table): array
    {
        $driver = $this->session->open();
        try {
            if (!$driver instanceof SupportsForeignKeys) {
                throw new RuntimeException('This engine does not support foreign key introspection.');
            }
            return $driver->getForeignKeys($database, $table);
        } finally {
            $driver->disconnect();
        }
    }
}
