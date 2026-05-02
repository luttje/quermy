<?php declare(strict_types=1);

namespace Quermy\Drivers\Capabilities\PostgreSQL;

use RuntimeException;

/** @mixin \Quermy\Drivers\PostgreSQLDriver */
trait SupportGetCreateTable
{
    public function getCreateTable(string $database, string $table): string
    {
        $this->ensureConnected();
        $this->validateIdent($table);

        // pg_get_tabledef is not a built-in; reconstruct from catalog.
        $qTbl = $this->pdo->quote($table);
        $stmt = $this->pdo->query(
            "SELECT 'CREATE TABLE public.' || quote_ident(c.relname) || ' (' || chr(10) || "
            . "string_agg("
            . "  '  ' || quote_ident(a.attname) || ' ' || pg_catalog.format_type(a.atttypid, a.atttypmod)"
            . "  || CASE WHEN a.attnotnull THEN ' NOT NULL' ELSE '' END"
            . "  || CASE WHEN ad.adbin IS NOT NULL THEN ' DEFAULT ' || pg_get_expr(ad.adbin, ad.adrelid) ELSE '' END,"
            . "  chr(10) || ','"
            . "  ORDER BY a.attnum"
            . ") || chr(10) || ');' AS ddl "
            . "FROM pg_class c "
            . "JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped "
            . "LEFT JOIN pg_attrdef ad ON ad.adrelid = c.oid AND ad.adnum = a.attnum "
            . "JOIN pg_namespace n ON n.oid = c.relnamespace "
            . "WHERE c.relname = $qTbl AND n.nspname = 'public' "
            . "GROUP BY c.relname"
        );
        $row = $stmt->fetch();
        if (!$row || !$row['ddl']) {
            throw new RuntimeException("Table not found: $table");
        }
        return $row['ddl'];
    }
}
